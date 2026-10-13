        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <title>Hospital Management System - Advanced Healthcare Information System</title>
        <meta name="description" content="A fully featured hospital management system for healthcare facilities, patient records, appointments, billing, and inventory management." />
        <meta name="author" content="MartDevelopers" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        
        <!-- App favicon -->
        <link rel="shortcut icon" href="assets/images/favicon.ico">
        
        <!-- Google Fonts for Modern Typography -->
        <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        
        <!-- Font Awesome 6 (Modern Icons) -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

        <!-- Plugins css -->
        <link href="assets/libs/flatpickr/flatpickr.min.css" rel="stylesheet" type="text/css" />

        <!-- App css - Core responsive frameworks -->
        <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
        
        <!-- Loading button css -->
        <link href="assets/libs/ladda/ladda-themeless.min.css" rel="stylesheet" type="text/css" />

        <!-- Footable css -->
        <link href="assets/libs/footable/footable.core.min.css" rel="stylesheet" type="text/css" />

        <!-- Custom CSS - Preserving existing GRAY/WHITE BUTTONS AND HEADERS WITH BLACK TEXT -->
        <style>
            /* ===== GRAY/WHITE COLOR SCHEME WITH BLACK TEXT (PRESERVED) ===== */
            
            /* Modern Font Override */
            body, h1, h2, h3, h4, h5, h6, .navbar, .card, .btn, .form-control, .table {
                font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            }
            
            /* Smooth Loading Animation */
            .page-wrapper {
                animation: fadeInPage 0.4s ease-out;
            }
            
            @keyframes fadeInPage {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            /* Modern Card Hover Effects */
            .card {
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                border: 1px solid rgba(0, 0, 0, 0.05);
            }
            
            .card:hover {
                transform: translateY(-4px);
                box-shadow: 0 20px 30px -12px rgba(0, 0, 0, 0.1);
            }
            
            /* ===== BUTTON STYLES - GRAY/WHITE WITH BLACK TEXT (PRESERVED) ===== */
            .btn {
                border-radius: 12px;
                font-weight: 600;
                letter-spacing: 0.3px;
                transition: all 0.2s ease;
            }
            
            /* Primary Button - Gray/White */
            .btn-primary {
                background: #e5e7eb !important;
                border: 1px solid #d1d5db !important;
                color: #111827 !important;
            }
            
            .btn-primary:hover {
                background: #d1d5db !important;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                color: #111827 !important;
            }
            
            /* Secondary Button */
            .btn-secondary {
                background: #f3f4f6 !important;
                border: 1px solid #d1d5db !important;
                color: #111827 !important;
            }
            
            .btn-secondary:hover {
                background: #e5e7eb !important;
                color: #111827 !important;
            }
            
            /* Success Button - Light Gray */
            .btn-success {
                background: #e5e7eb !important;
                border: 1px solid #d1d5db !important;
                color: #111827 !important;
            }
            
            .btn-success:hover {
                background: #d1d5db !important;
                color: #111827 !important;
            }
            
            /* Danger Button - Light Gray */
            .btn-danger {
                background: #e5e7eb !important;
                border: 1px solid #d1d5db !important;
                color: #111827 !important;
            }
            
            .btn-danger:hover {
                background: #d1d5db !important;
                color: #111827 !important;
            }
            
            /* Warning Button */
            .btn-warning {
                background: #e5e7eb !important;
                border: 1px solid #d1d5db !important;
                color: #111827 !important;
            }
            
            .btn-warning:hover {
                background: #d1d5db !important;
                color: #111827 !important;
            }
            
            /* Info Button */
            .btn-info {
                background: #e5e7eb !important;
                border: 1px solid #d1d5db !important;
                color: #111827 !important;
            }
            
            .btn-info:hover {
                background: #d1d5db !important;
                color: #111827 !important;
            }
            
            /* Outline Button - Gray border */
            .btn-outline-primary {
                border: 1.5px solid #9ca3af !important;
                color: #111827 !important;
                background: transparent !important;
            }
            
            .btn-outline-primary:hover {
                background: #e5e7eb !important;
                color: #111827 !important;
            }
            
            /* ===== HEADER/TABLE HEADER STYLES - GRAY WITH BLACK TEXT (PRESERVED) ===== */
            .table thead th {
                background: #f3f4f6 !important;
                color: #111827 !important;
                font-weight: 700;
                text-transform: uppercase;
                font-size: 0.75rem;
                letter-spacing: 0.5px;
                border: 1px solid #e5e7eb !important;
            }
            
            /* Card Header */
            .card-header {
                background: #f9fafb !important;
                border-bottom: 1px solid #e5e7eb !important;
                color: #111827 !important;
                font-weight: 700 !important;
            }
            
            /* Modal Header */
            .modal-header {
                background: #f9fafb !important;
                border-bottom: 1px solid #e5e7eb !important;
                color: #111827 !important;
            }
            
            .modal-title {
                color: #111827 !important;
                font-weight: 700 !important;
            }
            
            /* Page Headers */
            .page-title, .page-title-box h4, .header-title {
                color: #111827 !important;
                font-weight: 800 !important;
            }
            
            /* ===== FORM CONTROLS - LIGHT GRAY BACKGROUND ===== */
            .form-control, .form-select {
                border-radius: 12px;
                border: 1px solid #d1d5db !important;
                padding: 0.6rem 1rem;
                transition: all 0.2s ease;
                background: #ffffff !important;
                color: #111827 !important;
            }
            
            .form-control:focus, .form-select:focus {
                border-color: #9ca3af !important;
                box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.2) !important;
                background: #ffffff !important;
            }
            
            .form-control::placeholder {
                color: #6b7280 !important;
            }
            
            /* Labels */
            label, .form-label {
                color: #111827 !important;
                font-weight: 600 !important;
            }
            
            /* Table body */
            .table tbody td {
                color: #111827 !important;
                border-color: #e5e7eb !important;
            }
            
            .table tbody tr:hover {
                background: #f9fafb !important;
            }
            
            /* ===== ALERT STYLES ===== */
            .alert {
                border-radius: 16px;
                border: 1px solid #e5e7eb !important;
                background: #f9fafb !important;
                color: #111827 !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            }
            
            .alert-success {
                background: #f0fdf4 !important;
                border-color: #bbf7d0 !important;
                color: #166534 !important;
            }
            
            .alert-danger {
                background: #fef2f2 !important;
                border-color: #fecaca !important;
                color: #991b1b !important;
            }
            
            .alert-warning {
                background: #fffbeb !important;
                border-color: #fde68a !important;
                color: #92400e !important;
            }
            
            .alert-info {
                background: #eff6ff !important;
                border-color: #bfdbfe !important;
                color: #1e40af !important;
            }
            
            /* ===== MODAL STYLES ===== */
            .modal-content {
                border-radius: 24px;
                border: 1px solid #e5e7eb !important;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            }
            
            .modal-footer {
                border-top: 1px solid #e5e7eb !important;
                border-radius: 0 0 24px 24px;
                background: #f9fafb !important;
            }
            
            .modal-body {
                background: #ffffff !important;
                color: #111827 !important;
            }
            
            /* ===== DROPDOWN STYLES ===== */
            .dropdown-menu {
                border-radius: 16px;
                border: 1px solid #e5e7eb !important;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
                background: #ffffff !important;
            }
            
            .dropdown-item {
                border-radius: 10px;
                margin: 4px 8px;
                transition: all 0.2s ease;
                color: #111827 !important;
            }
            
            .dropdown-item:hover {
                background: #f3f4f6 !important;
                color: #111827 !important;
                transform: translateX(4px);
            }
            
            /* ===== BADGE STYLES ===== */
            .badge {
                padding: 0.4rem 0.8rem;
                border-radius: 12px;
                font-weight: 600;
                letter-spacing: 0.3px;
            }
            
            .badge-primary, .badge-secondary, .badge-success, .badge-danger, .badge-warning, .badge-info {
                background: #e5e7eb !important;
                color: #111827 !important;
                border: 1px solid #d1d5db !important;
            }
            
            /* ===== PROGRESS BAR ===== */
            .progress {
                border-radius: 12px;
                background: #e5e7eb !important;
            }
            
            .progress-bar {
                border-radius: 12px;
                background: #9ca3af !important;
            }
            
            /* ===== PAGINATION ===== */
            .pagination .page-link {
                border-radius: 10px;
                margin: 0 4px;
                border: 1px solid #e5e7eb !important;
                color: #111827 !important;
                transition: all 0.2s ease;
                background: #ffffff !important;
            }
            
            .pagination .page-link:hover {
                background: #f3f4f6 !important;
                color: #111827 !important;
                border-color: #d1d5db !important;
                transform: translateY(-2px);
            }
            
            .pagination .active .page-link {
                background: #e5e7eb !important;
                border-color: #d1d5db !important;
                color: #111827 !important;
            }
            
            /* ===== SCROLLBAR ===== */
            ::-webkit-scrollbar {
                width: 8px;
                height: 8px;
            }
            
            ::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 10px;
            }
            
            ::-webkit-scrollbar-thumb {
                background: #9ca3af;
                border-radius: 10px;
            }
            
            ::-webkit-scrollbar-thumb:hover {
                background: #6b7280;
            }
            
            /* ===== SWEETALERT CUSTOMIZATION ===== */
            .swal2-popup {
                font-family: 'Plus Jakarta Sans', 'Inter', sans-serif !important;
                border-radius: 24px !important;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            }
            
            .swal2-confirm {
                border-radius: 12px !important;
                background: #e5e7eb !important;
                color: #111827 !important;
                border: 1px solid #d1d5db !important;
            }
            
            /* ===== DASHBOARD STATS CARDS ===== */
            .stats-card {
                background: white;
                border-radius: 20px;
                padding: 1.25rem;
                transition: all 0.3s ease;
                border: 1px solid #e5e7eb !important;
            }
            
            .stats-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 15px 30px -10px rgba(0, 0, 0, 0.1);
            }
            
            .stats-card h3, .stats-card .text-primary, .stats-card .text-success, .stats-card .text-danger, .stats-card .text-warning, .stats-card .text-info {
                color: #111827 !important;
            }
            
            /* ===== RESPONSIVE TABLES ===== */
            @media (max-width: 768px) {
                .table-responsive-stack {
                    display: block;
                    width: 100%;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }
            }
            
            /* ===== PRINT STYLES ===== */
            @media print {
                .sidebar, .navbar, .footer, .btn {
                    display: none !important;
                }
                .main-content {
                    margin: 0 !important;
                    padding: 0 !important;
                }
            }
            
            /* ===== ADDITIONAL GRAY SCHEME ELEMENTS ===== */
            .bg-primary, .bg-success, .bg-danger, .bg-warning, .bg-info {
                background-color: #e5e7eb !important;
                color: #111827 !important;
            }
            
            .text-primary, .text-success, .text-danger, .text-warning, .text-info {
                color: #111827 !important;
            }
            
            .nav-tabs .nav-link {
                color: #111827 !important;
                border-color: #e5e7eb !important;
            }
            
            .nav-tabs .nav-link.active {
                background: #f9fafb !important;
                color: #111827 !important;
                border-color: #e5e7eb #e5e7eb #ffffff !important;
            }
            
            .list-group-item {
                background: #ffffff !important;
                color: #111827 !important;
                border-color: #e5e7eb !important;
            }
            
            .list-group-item:hover {
                background: #f9fafb !important;
            }
            
            /* Responsive Body Padding */
            body {
                padding-top: 70px;
            }
            
            /* Responsive Content Area */
            .content-page {
                margin-left: 250px;
                transition: all 0.3s ease;
            }
            
            @media (max-width: 768px) {
                .content-page {
                    margin-left: 0;
                }
            }
            
            /* Responsive Wrapper */
            #wrapper {
                width: 100%;
                overflow-x: hidden;
            }
        </style>
        
        <!--Load Sweet Alert Javascript-->
        <script src="assets/js/swal.js"></script>
        
        <!-- Modern SweetAlert2 (Enhanced but preserves original functionality) -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        
        <!-- jQuery (required for Bootstrap and MetisMenu) -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        
        <!-- Bootstrap JS (for responsive navbar toggle) -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
        
        <!-- MetisMenu (for sidebar collapse functionality) -->
        <script src="https://cdn.jsdelivr.net/npm/metismenu@3.0.7/dist/metismenujs.min.js"></script>
        
        <!--Inject SWAL - Preserving original PHP alert injection -->
        <?php if(isset($success)) { ?>
        <!--This code for injecting an alert-->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    if (typeof swal === 'function') {
                        swal("Success", "<?php echo addslashes($success); ?>", "success");
                    } else if (typeof Swal === 'function') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: '<?php echo addslashes($success); ?>',
                            confirmButtonColor: '#e5e7eb',
                            confirmButtonText: 'OK',
                            borderRadius: '20px',
                            timer: 3000
                        });
                    }
                }, 100);
            });
        </script>
        <?php } ?>

        <?php if(isset($err)) { ?>
        <!--This code for injecting an alert-->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    if (typeof swal === 'function') {
                        swal("Failed", "<?php echo addslashes($err); ?>", "error");
                    } else if (typeof Swal === 'function') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Failed',
                            text: '<?php echo addslashes($err); ?>',
                            confirmButtonColor: '#e5e7eb',
                            confirmButtonText: 'OK',
                            borderRadius: '20px',
                            timer: 3000
                        });
                    }
                }, 100);
            });
        </script>
        <?php } ?>
