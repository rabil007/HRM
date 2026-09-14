<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Support\Email\BuiltInEmailTemplates;
use App\Support\Email\SeedBuiltInEmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (BuiltInEmailTemplates::slugs() as $slug) {
            SeedBuiltInEmailTemplate::handle($slug);
        }
    }

    public static function seedDocumentShareTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('document_share');
    }

    public static function seedDocumentExpiryAlertTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('document_expiry_alert');
    }

    public static function seedCompanyDocumentExpiryAlertTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('company_document_expiry_alert');
    }

    public static function seedPayslipDeliveryTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('payslip_delivery');
    }

    public static function seedLeaveRequestSubmittedTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('leave_request_submitted');
    }

    public static function seedLeaveRequestUpdatedTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('leave_request_updated');
    }

    public static function seedLeaveRequestApproverActionRequiredTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('leave_request_approver_action_required');
    }

    public static function seedLeaveRequestNotificationOnlyTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('leave_request_notification_only');
    }

    public static function seedLeaveRequestApprovedTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('leave_request_approved');
    }

    public static function seedLeaveRequestRejectedTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('leave_request_rejected');
    }

    public static function seedPasswordResetTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('password_reset');
    }

    public static function seedUserInvitationTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('user_invitation');
    }

    public static function seedBulkSalaryDeclarationTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('bulk_salary_declaration');
    }

    public static function seedBulkSalaryDeclarationSignReminderTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('bulk_salary_declaration_sign_reminder');
    }

    public static function seedBulkSalaryCertificateTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('bulk_salary_certificate');
    }

    public static function seedCrewOperationalAlertDigestTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('crew_operational_alert_digest');
    }

    public static function seedDocumentRecipientActionRequestTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('document_recipient_action_request');
    }

    public static function seedDocumentRecipientActionReminderTemplate(): EmailTemplate
    {
        return SeedBuiltInEmailTemplate::handle('document_recipient_action_reminder');
    }
}
