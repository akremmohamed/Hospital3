<?php
/**
 * sidebar.php - Super Admin Navigation (UI Enhanced)
 * Updated with exact filenames from the super_admin directory and added title tooltips.
 * Removed bullet points, improved spacing, hover effects, and active states.
 * FIXED: duplicate ID removed, mobile scrolling support.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Africa/Addis_Ababa');

$branch_id = $_SESSION['branch_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$user_type = $_SESSION['user_type'] ?? '';
$is_super_admin = ($user_type === 'super_admin');
?>

<aside id="sidebar-wrapper">
    

    <div class="sidebar-menu">
        <div class="sidebar-brand">
        <a href="dashboard.php" class="logo">
            <i class="fas fa-crown"></i>
            <span class="logo-text">HMS ERP • Super Admin</span>
        </a>
    </div>
        <ul id="side-menu" class="metismenu">
            <!-- ENTERPRISE CORE DASHBOARDS -->
            <li class="menu-title"><span>Enterprise Core Dashboards</span></li>
            <li><a href="dashboard.php" title="Main Control Panel" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-tachometer-alt"></i><span>Main Control Panel</span></a></li>
            <li><a href="executive_kpi_dashboard.php" title="Executive KPI Dashboard" <?php echo basename($_SERVER['PHP_SELF']) == 'executive_kpi_dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i><span>Executive KPI Dashboard</span></a></li>
            <li><a href="branch_management.php" title="Multi-Branch Operations" <?php echo basename($_SERVER['PHP_SELF']) == 'branch_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-building"></i><span>Multi-Branch Operations</span></a></li>
            <li><a href="financial_tariff_master.php" title="Global Financial Master" <?php echo basename($_SERVER['PHP_SELF']) == 'financial_tariff_master.php' ? 'class="active"' : ''; ?>><i class="fas fa-coins"></i><span>Global Financial Master</span></a></li>

            <!-- GOVERNANCE & SECURITY CONTROLS -->
            <li class="menu-title"><span>Governance & Security</span></li>
            <li><a href="user_permission_rules.php" title="Dynamic Access Control (RBAC)" <?php echo basename($_SERVER['PHP_SELF']) == 'user_permission_rules.php' ? 'class="active"' : ''; ?>><i class="fas fa-shield-alt"></i><span>Dynamic Access Control (RBAC)</span></a></li>
            <li><a href="system_maintenance.php" title="System Maintenance Mode" <?php echo basename($_SERVER['PHP_SELF']) == 'system_maintenance.php' ? 'class="active"' : ''; ?>><i class="fas fa-wrench"></i><span>System Maintenance</span></a></li>

            <!-- HUMAN RESOURCE & CAPACITY PLANNING -->
            <li class="menu-title"><span>HR & Capacity Planning</span></li>
            <li><a href="hr_roster_management.php" title="HR Profile Provisioning" <?php echo basename($_SERVER['PHP_SELF']) == 'hr_roster_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users"></i><span>HR Profile Provisioning</span></a></li>
            <li><a href="clinical_infrastructure.php" title="Clinical Infrastructure Management" <?php echo basename($_SERVER['PHP_SELF']) == 'clinical_infrastructure.php' ? 'class="active"' : ''; ?>><i class="fas fa-calendar-alt"></i><span>Clinical Infrastructure Management</span></a></li>
            <li><a href="vital_legal_registries.php" title="Vital & Legal Registries" <?php echo basename($_SERVER['PHP_SELF']) == 'vital_legal_registries.php' ? 'class="active"' : ''; ?>><i class="fas fa-book"></i><span>Birth & Legal Death Registries</span></a></li>

            <!-- CLINICAL WORKFLOW CUSTOMIZATION -->
            <li class="menu-title"><span>Clinical Workflow</span></li>
            <li><a href="clinical_template_builder.php" title="Clinical Template Builder" <?php echo basename($_SERVER['PHP_SELF']) == 'clinical_template_builder.php' ? 'class="active"' : ''; ?>><i class="fas fa-file-alt"></i><span>Clinical Template Builder</span></a></li>
            <li><a href="referral_transfer_master.php" title="Referral & Transfer Master" <?php echo basename($_SERVER['PHP_SELF']) == 'referral_transfer_master.php' ? 'class="active"' : ''; ?>><i class="fas fa-exchange-alt"></i><span>Referral & Transfer Master</span></a></li>
            <li><a href="equipment_asset_manager.php" title="Biomedical Equipment Maintenance" <?php echo basename($_SERVER['PHP_SELF']) == 'equipment_asset_manager.php' ? 'class="active"' : ''; ?>><i class="fas fa-wrench"></i><span>Biomedical Equipment Maintenance</span></a></li>

            <!-- OPERATIONAL & SUPPLY CHAIN AUDITS -->
            <li class="menu-title"><span>Supply Chain & Audits</span></li>
            <li><a href="pharmacy_supply_master.php" title="Centralized Procurement Matrix" <?php echo basename($_SERVER['PHP_SELF']) == 'pharmacy_supply_master.php' ? 'class="active"' : ''; ?>><i class="fas fa-shopping-cart"></i><span>Centralized Procurement Matrix</span></a></li>
            <li><a href="pharmacy_inventory_audit.php" title="Pharmacy Stock & POS Audit" <?php echo basename($_SERVER['PHP_SELF']) == 'pharmacy_inventory_audit.php' ? 'class="active"' : ''; ?>><i class="fas fa-pills"></i><span>Pharmacy Stock & POS Audit</span></a></li>
            <li><a href="insurance_claims_desk.php" title="Advanced Insurance Claims Desk" <?php echo basename($_SERVER['PHP_SELF']) == 'insurance_claims_desk.php' ? 'class="active"' : ''; ?>><i class="fas fa-file-invoice"></i><span>Advanced Insurance Claims Desk</span></a></li>
        </ul>
    </div>
</aside>

<!-- Sidebar Toggle Button for Mobile (with unique ID) -->
<div class="sidebar-toggler">
    <button class="btn btn-link text-dark" id="sidebarMobileToggle"><i class="fas fa-bars"></i></button>
</div>

<script>
(function() {
    'use strict';

    function initMetisMenu() {
        if (typeof MetisMenu !== 'undefined') {
            try {
                const sideMenu = document.getElementById('side-menu');
                if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
                    new MetisMenu(sideMenu, {
                        toggle: true,
                        triggerElement: 'a.has-arrow',
                        parentTrigger: 'li',
                        subMenu: 'ul'
                    });
                    sideMenu.setAttribute('data-metismenu-initialized', 'true');
                }
            } catch (e) {
                console.warn('MetisMenu init failed:', e);
            }
        } else {
            setTimeout(initMetisMenu, 200);
        }
    }

    function handleSidebarCollapse() {
        const body = document.body;
        const collapsedState = localStorage.getItem('erp_sidebar_collapsed');
        if (collapsedState === 'true') {
            body.classList.add('erp-sidebar-collapsed');
        } else {
            body.classList.remove('erp-sidebar-collapsed');
        }
        const toggleBtn = document.querySelector('.sidebar-collapse-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                body.classList.toggle('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', body.classList.contains('erp-sidebar-collapsed'));
            });
        }
    }

    function initMobileToggle() {
        const mobileToggle = document.getElementById('sidebarMobileToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                document.body.classList.toggle('sidebar-enable');
                // Prevent body scroll when sidebar is open on mobile
                if (document.body.classList.contains('sidebar-enable')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            });
        }
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar-wrapper');
            const toggle = document.getElementById('sidebarMobileToggle');
            if (window.innerWidth <= 768 && sidebar && toggle && document.body.classList.contains('sidebar-enable')) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    document.body.classList.remove('sidebar-enable');
                    document.body.style.overflow = '';
                }
            }
        });
    }

    function handleResize() {
        if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) {
            document.body.classList.remove('sidebar-enable');
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        initMetisMenu();
        handleSidebarCollapse();
        initMobileToggle();
        window.addEventListener('resize', handleResize);
    });
})();
</script>

<style>
/* ========== SIDEBAR ENHANCED UI – NO BULLET POINTS, SMOOTH SPACING ========== */
#sidebar-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    width: 260px;
    height: 100%;
    background: #ffffff;
    border-right: 1px solid #e9ecef;
    z-index: 1000;
    transition: all 0.3s ease;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    overflow-y: auto;
    overflow-x: hidden;
}

body.erp-sidebar-collapsed #sidebar-wrapper {
    width: 80px;
}

body.erp-sidebar-collapsed .sidebar-brand .logo-text,
body.erp-sidebar-collapsed .metismenu span:not(.badge) {
    display: none;
}

body.erp-sidebar-collapsed .metismenu .badge {
    display: none;
}

body.erp-sidebar-collapsed .metismenu a {
    text-align: center;
    padding: 0.75rem 0;
}

body.erp-sidebar-collapsed .metismenu i {
    margin-right: 0;
    font-size: 1.25rem;
}

/* Brand area */
.sidebar-brand {
    padding: 1.2rem 1rem;
    text-align: center;
    border-bottom: 1px solid #e9ecef;
}

.sidebar-brand .logo {
    font-size: 1.25rem;
    font-weight: 700;
    text-decoration: none;
    color: #1e293b;
}

/* Menu container */
.sidebar-menu {
    padding: 1rem 0;
}

/* Remove all list bullets */
.metismenu,
.metismenu ul {
    list-style: none;
    margin: 0;
    padding: 0;
}

/* Menu titles */
.metismenu .menu-title {
    padding: 0.75rem 1rem;
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.5px;
}

/* Links */
.metismenu a {
    display: flex;
    align-items: center;
    padding: 0.625rem 1rem;
    color: #334155;
    text-decoration: none;
    transition: all 0.2s ease;
    border-left: 3px solid transparent;
    font-size: 0.9rem;
}

.metismenu a:hover {
    background-color: #f1f5f9;
    color: #2563eb;
    border-left-color: #2563eb;
}

.metismenu a.active {
    background-color: #eff6ff;
    color: #2563eb;
    border-left-color: #2563eb;
    font-weight: 500;
}

/* Icons */
.metismenu i {
    width: 2rem;
    font-size: 1.1rem;
    margin-right: 0.5rem;
    text-align: center;
    color: #64748b;
    transition: color 0.2s;
}

.metismenu a:hover i,
.metismenu a.active i {
    color: #2563eb;
}

/* Badges (if used) */
.metismenu .badge {
    margin-left: auto;
    font-size: 0.7rem;
    padding: 0.25rem 0.5rem;
}

/* Mobile toggle button */
.sidebar-toggler {
    position: fixed;
    bottom: 1rem;
    left: 0.5rem;
    z-index: 1100;
    display: none;
}

/* Mobile responsive */
@media (max-width: 768px) {
    #sidebar-wrapper {
        transform: translateX(-100%);
        width: 280px;
        transition: transform 0.3s ease;
    }
    body.sidebar-enable #sidebar-wrapper {
        transform: translateX(0);
    }
    .sidebar-toggler {
        display: block;
    }
    body.erp-sidebar-collapsed #sidebar-wrapper {
        width: 280px;
        transform: translateX(-100%);
    }
    body.erp-sidebar-collapsed.sidebar-enable #sidebar-wrapper {
        transform: translateX(0);
    }
    body.erp-sidebar-collapsed .sidebar-brand .logo-text,
    body.erp-sidebar-collapsed .metismenu span:not(.badge) {
        display: inline-block;
    }
    body.erp-sidebar-collapsed .metismenu a {
        text-align: left;
        padding: 0.625rem 1rem;
    }
    body.erp-sidebar-collapsed .metismenu i {
        margin-right: 0.5rem;
    }
    body.erp-sidebar-collapsed .metismenu .badge {
        display: inline-block;
    }
    /* Ensure main content is scrollable on mobile */
    .content-page {
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch;
    }
}

/* Custom scrollbar */
#sidebar-wrapper::-webkit-scrollbar {
    width: 5px;
}
#sidebar-wrapper::-webkit-scrollbar-track {
    background: #f1f1f1;
}
#sidebar-wrapper::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
</style>