<?php
/**
 * Permission Keys Mapping Configuration
 * Centralized definition of all system permission keys
 * 
 * @package HMS-ERP
 * @version 2.0
 */

return [
    // Navigation & Dashboard
    'receptionist_dashboard_access' => 'Access Reception Dashboard',
    'view_dashboard_stats' => 'View Dashboard Statistics',
    
    // Patient Management
    'view_patient_list' => 'View Patient List',
    'register_patient' => 'Register New Patient',
    'edit_patient' => 'Edit Patient Information',
    'delete_patient' => 'Delete Patient Record',
    'view_patient_kyc' => 'View Patient KYC Documents',
    'manage_family_linkage' => 'Manage Family Linkage',
    
    // Appointments
    'schedule_appointment' => 'Schedule Appointment',
    'cancel_appointment' => 'Cancel Appointment',
    'view_appointments' => 'View Appointments',
    'checkin_patient' => 'Check-in Patient',
    'send_reminders' => 'Send Reminders',
    
    // Triage & Queue
    'view_triage_queue' => 'View Triage Queue',
    'perform_triage' => 'Perform Triage',
    'view_live_queue' => 'View Live Queue',
    'view_waiting_room' => 'View Waiting Room',
    'view_emergency_alerts' => 'View Emergency Alerts',
    
    // Billing
    'view_invoices' => 'View Invoices',
    'create_invoice' => 'Create Invoice',
    'edit_invoice' => 'Edit Invoice',
    'process_payment' => 'Process Payment',
    'void_invoice' => 'Void Invoice',
    'view_insurance_claims' => 'View Insurance Claims',
    'process_insurance_claim' => 'Process Insurance Claim',
    'generate_report' => 'Generate Financial Report',
    'view_billing_info' => 'View Billing Information',
    
    // Admissions
    'view_admissions' => 'View Admissions',
    'manage_admissions' => 'Manage Admissions',
    'assign_bed' => 'Assign Bed',
    'manage_visitors' => 'Manage Visitors',
    
    // Emergency & Logs
    'view_emergency_logs' => 'View Emergency Logs',
    'manage_birth_records' => 'Manage Birth Records',
    'manage_death_records' => 'Manage Death Records',
    
    // Laboratory
    'view_lab_orders' => 'View Lab Orders',
    'create_lab_order' => 'Create Lab Order',
    'view_lab_results' => 'View Lab Results',
    
    // Pharmacy
    'view_prescriptions' => 'View Prescriptions',
    'dispense_medication' => 'Dispense Medication',
    'view_inventory' => 'View Inventory',
    'update_inventory' => 'Update Inventory',
    
    // Reports
    'view_reports' => 'View Reports',
    'export_data' => 'Export Data',
    
    // Administration
    'view_notifications' => 'View Notifications',
    'view_activity_log' => 'View Activity Log',
    'view_audit_logs' => 'View Audit Logs',
    'manage_users' => 'Manage Users',
    'manage_roles' => 'Manage Roles',
    'manage_branches' => 'Manage Branches',
    'manage_departments' => 'Manage Departments',
    'manage_permissions' => 'Manage Permissions',
    'view_system_settings' => 'View System Settings',
    'edit_system_settings' => 'Edit System Settings'
];