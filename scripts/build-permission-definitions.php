<?php

use App\Support\Authorization\ApplicationPermissionDefinitions;

/**
 * One-off generator for ApplicationPermissionDefinitions.php.
 * Run: php scripts/build-permission-definitions.php
 */
$seederPath = __DIR__.'/../database/seeders/PermissionsSeeder.php';
$content = file_get_contents($seederPath);

if (preg_match('/\$permissions\s*=\s*\[(.*?)\];/s', $content, $permissionsMatch)) {
    preg_match_all("/'([^']+)'/", $permissionsMatch[1], $matches);
    $names = array_values(array_filter($matches[1], fn (string $p): bool => str_contains($p, '.')));
} else {
    require_once __DIR__.'/../vendor/autoload.php';

    $names = array_column(
        ApplicationPermissionDefinitions::all(),
        'name',
    );

    if ($names === []) {
        fwrite(STDERR, "No permission names found. Add definitions or restore the seeder list.\n");
        exit(1);
    }
}

$names = array_values(array_unique($names));
sort($names);

$groupAliases = [
    'company_documents' => 'Companies',
    'bulk_documents' => 'Documents',
];

$resourceLabels = [
    'companies' => 'Companies',
    'branches' => 'Branches',
    'departments' => 'Departments',
    'positions' => 'Positions',
    'roles' => 'Roles',
    'users' => 'Users',
    'employees' => 'Employees',
    'documents' => 'Employee Documents',
    'company_documents' => 'Company Documents',
    'bulk_documents' => 'Bulk Documents',
    'contracts' => 'Contracts',
    'bank_accounts' => 'Bank Accounts',
    'training' => 'Training Records',
    'sea_services' => 'Sea Service Records',
    'education' => 'Education Records',
    'work_experience' => 'Work Experience Records',
    'vaccination' => 'Vaccination Records',
    'languages' => 'Language Records',
    'announcements' => 'Announcements',
    'employee_profile_templates' => 'Employee Profile Templates',
    'audit' => 'Audit',
    'attendance' => 'Attendance',
    'crew_operations' => 'Crew Operations',
    'payroll' => 'Payroll',
    'reports' => 'Reports',
    'hikvision' => 'Hikvision',
    'settings' => 'Settings',
];

$special = [
    'settings.security.view' => ['View Security Settings', 'Allows the user to view company security settings for the active company.'],
    'settings.security.update' => ['Update Security Settings', 'Allows the user to change company security settings for the active company.'],
    'settings.appearance.view' => ['View Appearance Settings', 'Allows the user to view company appearance and theme settings for the active company.'],
    'settings.application.view' => ['View Application Settings (Legacy)', 'Retained for compatibility. Does not authorize platform-wide application settings, which require platform access instead.'],
    'settings.application.update' => ['Update Application Settings (Legacy)', 'Retained for compatibility. Does not authorize changes to platform-wide application settings, which require platform access instead.'],
    'settings.integrations.whatsapp.view' => ['View WhatsApp Integration (Legacy)', 'Retained for compatibility. Global WhatsApp integration is governed by platform access, not this tenant permission.'],
    'settings.integrations.whatsapp.update' => ['Update WhatsApp Integration (Legacy)', 'Retained for compatibility. Global WhatsApp credential changes require platform access and privileged two-factor when enforced.'],
    'settings.integrations.hikvision.view' => ['View Hikvision Integration', 'Allows the user to view Hikvision access-control integration settings for the active company.'],
    'settings.integrations.hikvision.update' => ['Update Hikvision Integration', 'Allows the user to configure Hikvision device endpoints, credentials, and sync settings for the active company.'],
    'settings.integrations.whatsapp-templates.view' => ['View WhatsApp Templates (Legacy)', 'Retained for compatibility. The global WhatsApp template library is governed by platform access.'],
    'settings.integrations.whatsapp-templates.create' => ['Create WhatsApp Templates (Legacy)', 'Retained for compatibility. Creating global WhatsApp templates requires platform access.'],
    'settings.integrations.whatsapp-templates.update' => ['Update WhatsApp Templates (Legacy)', 'Retained for compatibility. Updating global WhatsApp templates requires platform access.'],
    'settings.integrations.whatsapp-templates.delete' => ['Delete WhatsApp Templates (Legacy)', 'Retained for compatibility. Deleting global WhatsApp templates requires platform access.'],
    'settings.integrations.email-templates.view' => ['View Email Templates (Legacy)', 'Retained for compatibility. The global email template library is governed by platform access.'],
    'settings.integrations.email-templates.create' => ['Create Email Templates (Legacy)', 'Retained for compatibility. Creating global email templates requires platform access.'],
    'settings.integrations.email-templates.update' => ['Update Email Templates (Legacy)', 'Retained for compatibility. Updating global email templates requires platform access.'],
    'settings.integrations.email-templates.delete' => ['Delete Email Templates (Legacy)', 'Retained for compatibility. Deleting global email templates requires platform access.'],
    'bulk_documents.view' => ['View Bulk Documents', 'Allows the user to view bulk document generation runs and tracked outputs for the active company.'],
    'bulk_documents.generate' => ['Generate Bulk Documents', 'Allows the user to start bulk document generation jobs for eligible employees in the active company.'],
    'bulk_documents.delete' => ['Delete Bulk Documents', 'Allows the user to delete bulk document generation runs according to existing bulk document deletion rules.'],
    'bulk_documents.email' => ['Email Bulk Documents', 'Allows the user to email generated bulk documents to recipients according to existing delivery rules.'],
    'documents.download' => ['Download Employee Documents', 'Allows the user to download employee documents they are otherwise authorized to access in the active company.'],
    'documents.share' => ['Share Employee Documents', 'Allows the user to create or manage share links for employee documents according to existing sharing rules.'],
    'documents.upload' => ['Upload Employee Documents', 'Allows the user to upload employee documents for employees they are authorized to manage in the active company.'],
    'documents.delete' => ['Delete Employee Documents', 'Allows the user to delete employee documents according to the application\'s existing document deletion rules.'],
    'documents.templates.view' => ['View Document Templates', 'Allows the user to view document generation templates available to the active company.'],
    'documents.templates.create' => ['Create Document Templates', 'Allows the user to create document generation templates for the active company.'],
    'documents.templates.update' => ['Update Document Templates', 'Allows the user to update document generation templates for the active company.'],
    'documents.templates.delete' => ['Delete Document Templates', 'Allows the user to delete document generation templates according to existing template deletion rules.'],
    'documents.requests.view' => ['View Document Approval Requests', 'Allows the user to view document approval requests within the active company.'],
    'documents.requests.create' => ['Create Document Approval Requests', 'Allows the user to submit document records for multi-stage approval workflows.'],
    'documents.requests.review' => ['Review Document Approval Requests', 'Allows the user to act on document approval tasks assigned to them at review stages.'],
    'documents.requests.approve' => ['Approve Document Approval Requests', 'Allows the user to approve document approval tasks assigned to them at approval stages.'],
    'documents.requests.cancel' => ['Cancel Document Approval Requests', 'Allows the user to cancel open document approval requests according to existing workflow rules.'],
    'documents.workflow-presets.view' => ['View Document Workflow Presets', 'Allows the user to view reusable document approval workflow presets for the active company.'],
    'documents.workflow-presets.create' => ['Create Document Workflow Presets', 'Allows the user to create document approval workflow presets for the active company.'],
    'documents.workflow-presets.update' => ['Update Document Workflow Presets', 'Allows the user to update document approval workflow presets for the active company.'],
    'documents.workflow-presets.delete' => ['Delete Document Workflow Presets', 'Allows the user to delete document approval workflow presets according to existing rules.'],
    'documents.signing-presets.view' => ['View Document Signing Presets', 'Allows the user to view reusable document signing presets for the active company.'],
    'documents.signing-presets.create' => ['Create Document Signing Presets', 'Allows the user to create document signing presets for the active company.'],
    'documents.signing-presets.update' => ['Update Document Signing Presets', 'Allows the user to update document signing presets for the active company.'],
    'documents.signing-presets.delete' => ['Delete Document Signing Presets', 'Allows the user to delete document signing presets according to existing rules.'],
    'documents.recipient-requests.view' => ['View Document Recipient Requests', 'Allows the user to browse unified document signing and acknowledgement requests in the active company.'],
    'documents.recipient-requests.create' => ['Create Document Recipient Requests', 'Allows the user to start signing flows, resend recipient email, and regenerate public tokens for eligible requests.'],
    'documents.recipient-requests.cancel' => ['Cancel Document Recipient Requests', 'Allows the user to cancel open document recipient signing or acknowledgement requests.'],
    'documents.recipient-requests.respond' => ['Respond to Document Recipient Requests', 'Allows the user to complete assigned manager or company signatory steps on specific recipient requests.'],
    'documents.recipient-automation.view' => ['View Document Recipient Automation', 'Allows the user to view company reminder automation settings for document recipient requests.'],
    'documents.recipient-automation.update' => ['Update Document Recipient Automation', 'Allows the user to change company reminder automation settings for document recipient requests.'],
    'company_documents.view' => ['View Company Documents', 'Allows the user to view the company and branch document library for the active company.'],
    'company_documents.upload' => ['Upload Company Documents', 'Allows the user to upload documents to the company or branch document library.'],
    'company_documents.update' => ['Update Company Documents', 'Allows the user to update company or branch document metadata and files according to existing rules.'],
    'company_documents.download' => ['Download Company Documents', 'Allows the user to download company or branch documents they are authorized to access.'],
    'company_documents.delete' => ['Delete Company Documents', 'Allows the user to delete company or branch documents according to existing deletion rules.'],
    'company_documents.manage_notifications' => ['Manage Company Document Expiry Notifications', 'Allows the user to configure per-company document expiry alert recipients for company and branch documents.'],
    'contracts.salary_revisions.view' => ['View Salary Revisions', 'Allows the user to view contract salary revision history for employees in the active company.'],
    'contracts.salary_revisions.create' => ['Create Salary Revisions', 'Allows the user to record new salary revisions on employee contracts in the active company.'],
    'contracts.salary_revisions.update' => ['Update Salary Revisions', 'Allows the user to update salary revision records according to existing contract rules.'],
    'contracts.salary_revisions.delete' => ['Delete Salary Revisions', 'Allows the user to delete salary revision records according to existing contract rules.'],
    'employees.salary_certificate.print' => ['Print Salary Certificates', 'Allows the user to print employee salary certificates for records they are authorized to access.'],
    'employees.salary_declaration.print' => ['Print Salary Declarations', 'Allows the user to print employee salary declarations for records they are authorized to access.'],
    'users.password_reset' => ['Send User Password Reset', 'Allows the user to send a Fortify password reset link to a home-company user identity.'],
    'users.sessions.revoke' => ['Revoke User Sessions', 'Allows the user to invalidate active sessions and remember tokens for a home-company user identity.'],
    'attendance.overview.view' => ['View Attendance Overview', 'Allows the user to view the attendance overview dashboard for the active company.'],
    'attendance.records.manage' => ['Manage Attendance Records', 'Allows the user to view, create, update, and export attendance records for employees in the active company.'],
    'attendance.leave-requests.view_all' => ['View All Leave Requests', 'Allows the user to view leave requests for all employees in the active company, not only their own linked employee.'],
    'attendance.leave-requests.delete_any' => ['Administratively Delete Leave Requests', 'Allows the user to void and remove leave requests in any workflow status, including reversing balances and preserving audit history.'],
    'attendance.leave-requests.approve' => ['Approve Leave Requests', 'Allows the user to approve or reject leave requests at workflow steps assigned to them.'],
    'attendance.leave-approval-policies.view' => ['View Leave Approval Policies', 'Allows the user to view leave approval policy definitions for the active company.'],
    'attendance.leave-approval-policies.create' => ['Create Leave Approval Policies', 'Allows the user to create leave approval policies for the active company.'],
    'attendance.leave-approval-policies.update' => ['Update Leave Approval Policies', 'Allows the user to update leave approval policies for the active company.'],
    'attendance.leave-approval-policies.delete' => ['Delete Leave Approval Policies', 'Allows the user to delete leave approval policies according to existing rules.'],
    'attendance.leave-approval-settings.view' => ['View Leave Approval Settings', 'Allows the user to view company leave approver defaults and leave email notification switches.'],
    'attendance.leave-approval-settings.update' => ['Update Leave Approval Settings', 'Allows the user to change company leave approver defaults and leave email notification switches.'],
    'crew_operations.overview.view' => ['View Crew Operations Overview', 'Allows the user to view the crew operations dashboard for the active company.'],
    'crew_operations.vessel_manning.view' => ['View Vessel Manning', 'Allows the user to view vessel manning requirements and assignments for the active company.'],
    'crew_operations.vessel_manning.create' => ['Create Vessel Manning', 'Allows the user to create vessel manning records for the active company.'],
    'crew_operations.vessel_manning.update' => ['Update Vessel Manning', 'Allows the user to update vessel manning records for the active company.'],
    'crew_operations.vessel_manning.delete' => ['Delete Vessel Manning', 'Allows the user to delete vessel manning records according to existing rules.'],
    'crew_operations.planning.view' => ['View Crew Planning', 'Allows the user to view crew planning boards and schedules for the active company.'],
    'crew_operations.planning.create' => ['Create Crew Planning Entries', 'Allows the user to create crew planning entries for the active company.'],
    'crew_operations.planning.update' => ['Update Crew Planning Entries', 'Allows the user to update crew planning entries without bypassing movement workflow rules.'],
    'crew_operations.planning.delete' => ['Delete Crew Planning Entries', 'Allows the user to delete crew planning entries according to existing rules.'],
    'crew_operations.settings.view' => ['View Crew Operations Settings', 'Allows the user to view crew operations configuration for the active company.'],
    'crew_operations.settings.update' => ['Update Crew Operations Settings', 'Allows the user to change crew operations configuration for the active company.'],
    'crew_operations.assignments.view' => ['View Crew Assignments', 'Allows the user to view crew assignments and mobilisation records for the active company.'],
    'crew_operations.assignments.create' => ['Create Crew Assignments', 'Allows the user to create crew assignments and begin a new crew mobilisation cycle.'],
    'crew_operations.assignments.update' => ['Update Crew Assignments', 'Allows the user to update permitted crew assignment details without bypassing movement workflow rules.'],
    'crew_operations.assignments.cancel' => ['Cancel Crew Assignments', 'Allows the user to cancel crew assignments according to existing crew assignment cancellation rules.'],
    'crew_operations.assignments.void' => ['Void Erroneous Crew Assignments', 'Allows the user to void erroneous crew assignments when additional void guards permit the action. High-trust permission.'],
    'crew_operations.movements.perform' => ['Perform Crew Movements', 'Allows the user to execute crew movement steps in the active assignment workflow.'],
    'crew_operations.corrections.view' => ['View Crew Movement Corrections', 'Allows the user to view crew movement correction requests and history for the active company.'],
    'crew_operations.corrections.request' => ['Request Crew Movement Corrections', 'Allows the user to submit crew movement correction requests according to the existing correction workflow.'],
    'crew_operations.corrections.approve' => ['Approve Crew Movement Corrections', 'Allows the user to review, approve, or reject pending crew movement correction requests for the active company.'],
    'crew_operations.corrections.override' => ['Override Crew Movement Corrections', 'Allows a high-trust user to apply a validated crew movement correction immediately without waiting for a separate approver. Normal correction integrity rules still apply.'],
    'reports.crew_movement_history.view' => ['View Crew Movement History Report', 'Allows the user to view the crew movement history report for the active company.'],
    'reports.crew_movement_history.export' => ['Export Crew Movement History Report', 'Allows the user to export crew movement history data for the active company.'],
    'reports.leave.view' => ['View Leave Report', 'Allows the user to view leave reporting data available to them within the active company.'],
    'reports.leave.export' => ['Export Leave Report', 'Allows the user to export leave reporting data available to them within the active company.'],
    'audit.view' => ['View Activity Log', 'Allows the user to view application activity and audit history for records they are otherwise authorized to access.'],
    'payroll.overview.view' => ['View Payroll Overview', 'Allows the user to view the payroll overview dashboard for the active company.'],
    'payroll.periods.revert_to_draft' => ['Revert Payroll Period to Draft', 'Allows the user to revert a payroll period to draft status according to existing payroll workflow rules.'],
    'payroll.periods.revert_to_approved' => ['Revert Payroll Period to Approved', 'Allows the user to revert a payroll period to approved status according to existing payroll workflow rules.'],
    'payroll.periods.revert_to_processing' => ['Revert Payroll Period to Processing', 'Allows the user to revert a payroll period to processing status according to existing payroll workflow rules.'],
    'payroll.periods.approve' => ['Approve Payroll Period', 'Allows the user to approve a payroll period at the workflow stage controlled by this permission.'],
    'payroll.periods.mark_paid' => ['Mark Payroll Period Paid', 'Allows the user to mark a payroll period as paid according to existing payroll workflow rules.'],
    'payroll.periods.cancel' => ['Cancel Payroll Period', 'Allows the user to cancel a payroll period according to existing payroll workflow rules.'],
    'payroll.periods.recalculate' => ['Recalculate Payroll Period', 'Allows the user to recalculate payroll figures for a period according to existing payroll rules.'],
    'payroll.crew_timesheets.view' => ['View Crew Timesheets', 'Allows the user to view crew timesheet preparation and related payroll timesheet data for the active company.'],
    'payroll.crew_timesheets.create' => ['Create Crew Timesheets', 'Allows the user to create manual or import crew timesheet records for draft payroll periods.'],
    'payroll.crew_timesheets.update' => ['Update Crew Timesheets', 'Allows the user to update crew timesheet records within permitted draft workflow states.'],
    'payroll.crew_timesheets.import' => ['Import Crew Timesheets', 'Allows the user to import crew timesheet data into draft payroll periods.'],
    'payroll.crew_timesheets.clear' => ['Clear Crew Timesheets', 'Allows the user to clear all manual or imported timesheets on a draft crew payroll period.'],
    'payroll.crew_timesheets.prepare' => ['Prepare Crew Timesheets', 'Allows the user to create a new draft crew timesheet preparation version.'],
    'payroll.crew_timesheets.submit' => ['Submit Crew Timesheets', 'Allows the user to submit draft crew timesheet preparations or individual timesheets for approval.'],
    'payroll.crew_timesheets.approve' => ['Approve Crew Timesheets', 'Allows the user to approve submitted crew timesheet preparations or individual timesheets.'],
    'payroll.crew_timesheets.return' => ['Return Crew Timesheets', 'Allows the user to return submitted crew timesheet preparations or individual timesheets with notes.'],
    'payroll.crew_timesheets.apply_approved' => ['Apply Approved Crew Timesheets', 'Allows the user to apply an approved crew timesheet preparation to crew timesheets.'],
    'payroll.crew_timesheets.skip_timeline' => ['Skip Crew Timesheet Timeline Entries', 'Allows the user to skip or restore an employee\'s crew timesheet data for a draft preparation version.'],
    'payroll.salary_inputs.view' => ['View Payroll Salary Inputs', 'Allows the user to view supplemental salary inputs used in payroll calculations for the active company.'],
    'payroll.salary_inputs.create' => ['Create Payroll Salary Inputs', 'Allows the user to create supplemental salary inputs for payroll calculations in the active company.'],
    'payroll.salary_inputs.update' => ['Update Payroll Salary Inputs', 'Allows the user to update supplemental salary inputs according to existing payroll rules.'],
    'payroll.salary_inputs.delete' => ['Delete Payroll Salary Inputs', 'Allows the user to delete supplemental salary inputs according to existing payroll rules.'],
    'payroll.records.view' => ['View Payroll Records', 'Allows the user to view payroll periods, employee payroll records, calculations, and related payroll information.'],
    'payroll.payslips.generate' => ['Generate Payslips', 'Allows the user to generate employee payslips for payroll periods they are authorized to access.'],
    'payroll.payslips.email' => ['Email Payslips', 'Allows the user to email generated payslips to employees according to existing delivery rules.'],
    'payroll.wps.export' => ['Export WPS Files', 'Allows the user to export WPS payroll files for authorized payroll periods in the active company.'],
    'hikvision.persons.view' => ['View Hikvision Persons', 'Allows the user to view Hikvision person records linked to the active company.'],
    'hikvision.persons.sync' => ['Sync Hikvision Persons', 'Allows the user to synchronize Hikvision person records from connected devices.'],
    'hikvision.persons.create' => ['Create Hikvision Persons', 'Allows the user to create Hikvision person records for the active company.'],
    'hikvision.persons.update' => ['Update Hikvision Persons', 'Allows the user to update Hikvision person records for the active company.'],
    'hikvision.persons.delete' => ['Delete Hikvision Persons', 'Allows the user to delete Hikvision person records according to existing integration rules.'],
    'hikvision.persons.link' => ['Link Hikvision Persons', 'Allows the user to link Hikvision persons to employees in the active company.'],
    'hikvision.webhook.manage' => ['Manage Hikvision Webhooks', 'Allows the user to configure Hikvision webhook endpoints and related integration settings for the active company.'],
    'hikvision.devices.view' => ['View Hikvision Devices', 'Allows the user to view Hikvision access-control devices registered for the active company.'],
    'hikvision.devices.sync' => ['Sync Hikvision Devices', 'Allows the user to synchronize Hikvision device information from connected endpoints.'],
    'hikvision.events.view' => ['View Hikvision Events', 'Allows the user to view Hikvision access events recorded for the active company.'],
    'hikvision.events.fetch' => ['Fetch Hikvision Events', 'Allows the user to trigger retrieval of Hikvision access events from connected devices.'],
    'announcements.publish' => ['Publish Announcements', 'Allows the user to publish or schedule announcements for delivery to targeted company audiences.'],
    'announcements.cancel' => ['Cancel Announcements', 'Allows the user to cancel scheduled or in-progress announcements according to existing rules.'],
    'announcements.retry' => ['Retry Announcements', 'Allows the user to retry failed announcement deliveries according to existing rules.'],
    'announcements.download_attachments' => ['Download Announcement Attachments', 'Allows the user to download attachments from announcements they are authorized to view.'],
];

function titleCase(string $value): string
{
    return implode(' ', array_map(
        fn (string $word): string => ucfirst(str_replace('-', ' ', $word)),
        preg_split('/[-_]/', $value) ?: []
    ));
}

function resolveGroup(string $name): string
{
    global $groupAliases, $resourceLabels;
    $root = explode('.', $name)[0];

    if (isset($groupAliases[$root])) {
        return $groupAliases[$root];
    }

    if (str_starts_with($name, 'settings.master-data.')) {
        return 'Settings';
    }

    return $resourceLabels[$root] ?? titleCase(str_replace('_', ' ', $root));
}

function resolveMasterDataResource(string $name): string
{
    $parts = explode('.', $name);
    $resource = $parts[2] ?? 'record';

    return match ($resource) {
        'visa-types' => 'visa types',
        'company-visa-types' => 'company visa types',
        'approval-locations' => 'approval locations',
        'sssa-options' => 'SSSA options',
        'vessel-types' => 'vessel types',
        'document-types' => 'document types',
        default => str_replace('-', ' ', $resource),
    };
}

function buildDefault(string $name): array
{
    global $special, $resourceLabels;

    if (isset($special[$name])) {
        [$label, $description] = $special[$name];

        return [
            'name' => $name,
            'label' => $label,
            'description' => $description,
            'group' => resolveGroup($name),
        ];
    }

    $parts = explode('.', $name);
    $root = $parts[0];
    $action = $parts[count($parts) - 1];
    $group = resolveGroup($name);

    if (str_starts_with($name, 'settings.master-data.')) {
        $resource = resolveMasterDataResource($name);
        $resourceTitle = titleCase($resource);

        return match ($action) {
            'view' => [
                'name' => $name,
                'label' => "View {$resourceTitle}",
                'description' => "Allows the user to view {$resource} master data available to the active company.",
                'group' => $group,
            ],
            'create' => [
                'name' => $name,
                'label' => "Create {$resourceTitle}",
                'description' => "Allows the user to create new {$resource} master data records for the active company.",
                'group' => $group,
            ],
            'update' => [
                'name' => $name,
                'label' => "Update {$resourceTitle}",
                'description' => "Allows the user to update existing {$resource} master data records within the active company.",
                'group' => $group,
            ],
            'delete' => [
                'name' => $name,
                'label' => "Delete {$resourceTitle}",
                'description' => "Allows the user to delete {$resource} master data records according to existing usage-protection rules.",
                'group' => $group,
            ],
            default => [
                'name' => $name,
                'label' => titleCase(str_replace('.', ' ', substr($name, strlen('settings.master-data.')))),
                'description' => "Allows the user to perform the {$action} action on {$resource} master data within the active company.",
                'group' => $group,
            ],
        };
    }

    $resourceLabel = $resourceLabels[$root] ?? titleCase(str_replace('_', ' ', $root));
    $nested = count($parts) > 2 ? titleCase(str_replace(['.', '-', '_'], ' ', implode(' ', array_slice($parts, 1, -1)))) : null;

    return match ($action) {
        'view' => [
            'name' => $name,
            'label' => $nested ? "View {$nested}" : "View {$resourceLabel}",
            'description' => $nested
                ? "Allows the user to view {$nested} records available within the active company."
                : "Allows the user to view {$resourceLabel} records available within the active company.",
            'group' => $group,
        ],
        'create' => [
            'name' => $name,
            'label' => $nested ? "Create {$nested}" : "Create {$resourceLabel}",
            'description' => $nested
                ? "Allows the user to create new {$nested} records for the active company."
                : "Allows the user to create new {$resourceLabel} records for the active company.",
            'group' => $group,
        ],
        'update' => [
            'name' => $name,
            'label' => $nested ? "Update {$nested}" : "Update {$resourceLabel}",
            'description' => $nested
                ? "Allows the user to update existing {$nested} records within the active company."
                : "Allows the user to update existing {$resourceLabel} records within the active company.",
            'group' => $group,
        ],
        'delete' => [
            'name' => $name,
            'label' => $nested ? "Delete {$nested}" : "Delete {$resourceLabel}",
            'description' => $nested
                ? "Allows the user to delete {$nested} records according to the application's existing deletion rules."
                : "Allows the user to delete {$resourceLabel} records according to the application's existing deletion rules.",
            'group' => $group,
        ],
        'export' => [
            'name' => $name,
            'label' => "Export {$resourceLabel}",
            'description' => "Allows the user to export {$resourceLabel} data for the active company according to existing export rules.",
            'group' => $group,
        ],
        'import' => [
            'name' => $name,
            'label' => "Import {$resourceLabel}",
            'description' => "Allows the user to import {$resourceLabel} data into the active company using existing import workflows.",
            'group' => $group,
        ],
        default => [
            'name' => $name,
            'label' => titleCase(str_replace(['.', '-', '_'], ' ', substr($name, strlen($root) + 1))),
            'description' => "Allows the user to perform the {$action} action on {$resourceLabel} within the active company.",
            'group' => $group,
        ],
    };
}

$definitions = [];

foreach ($names as $name) {
    $definitions[] = buildDefault($name);
}

$outputPath = __DIR__.'/../app/Support/Authorization/ApplicationPermissionDefinitions.php';
$export = var_export($definitions, true);
$php = <<<PHP
<?php

namespace App\Support\Authorization;

/**
 * Authoritative application permission metadata catalog.
 *
 * Every new permission must be added here with name, label, description, and group
 * before it is seeded into the permissions table.
 *
 * @return list<array{name: string, label: string, description: string, group: string}>
 */
final class ApplicationPermissionDefinitions
{
    public static function all(): array
    {
        return {$export};
    }
}

PHP;

file_put_contents($outputPath, $php);
echo 'Wrote '.count($definitions)." definitions to {$outputPath}\n";
