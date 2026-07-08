<?php

namespace Database\Seeders;

use App\Enums\EmailTemplateCategory;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        self::seedPayslipDeliveryTemplate();
        self::seedLeaveRequestSubmittedTemplate();
        self::seedLeaveRequestApprovedTemplate();
        self::seedLeaveRequestRejectedTemplate();
        self::seedPasswordResetTemplate();
        self::seedBulkSalaryDeclarationTemplate();
        self::seedBulkSalaryCertificateTemplate();
    }

    public static function seedPayslipDeliveryTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'payslip_delivery'],
            [
                'label' => 'Payslip delivery',
                'category' => EmailTemplateCategory::Payroll,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Your payslip for {{period_name}} — {{company_name}}',
                'body_html' => self::payslipDeliveryBody(),
                'enabled' => true,
                'sort_order' => 0,
            ],
        );

        if (! $template->is_default) {
            $template->markAsDefaultForCategory();
        }

        return $template->fresh();
    }

    public static function seedLeaveRequestSubmittedTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'leave_request_submitted'],
            [
                'label' => 'Leave request submitted',
                'category' => EmailTemplateCategory::Hr,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'New leave request — {{employee_name}} ({{leave_type}})',
                'body_html' => self::leaveRequestSubmittedBody(),
                'enabled' => true,
                'sort_order' => 0,
            ],
        );

        if (! $template->is_default) {
            $template->markAsDefaultForCategory();
        }

        return $template->fresh();
    }

    public static function seedLeaveRequestApprovedTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'leave_request_approved'],
            [
                'label' => 'Leave request approved',
                'category' => EmailTemplateCategory::Hr,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Leave request approved — {{leave_type}}',
                'body_html' => self::leaveRequestApprovedBody(),
                'enabled' => true,
                'sort_order' => 1,
            ],
        );

        return $template->fresh();
    }

    public static function seedLeaveRequestRejectedTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'leave_request_rejected'],
            [
                'label' => 'Leave request declined',
                'category' => EmailTemplateCategory::Hr,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Leave request declined — {{leave_type}}',
                'body_html' => self::leaveRequestRejectedBody(),
                'enabled' => true,
                'sort_order' => 2,
            ],
        );

        return $template->fresh();
    }

    private static function payslipDeliveryBody(): string
    {
        return <<<'TEXT'
Dear {{employee_name}},

Please find your payslip for {{period_name}} attached to this email.

Employee no.: {{employee_no}}
Net salary: {{net_salary}}

If you have any questions about your payslip, please contact HR.

Thank you,
{{company_name}}
TEXT;
    }

    private static function leaveRequestSubmittedBody(): string
    {
        return <<<'TEXT'
A new leave request has been submitted and is pending your review.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT;
    }

    private static function leaveRequestApprovedBody(): string
    {
        return <<<'TEXT'
Your leave request has been approved.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT;
    }

    private static function leaveRequestRejectedBody(): string
    {
        return <<<'TEXT'
Your leave request has been declined.

Reason for decline: {{rejection_reason}}

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT;
    }

    public static function seedPasswordResetTemplate(): EmailTemplate
    {
        $template = EmailTemplate::query()->updateOrCreate(
            ['slug' => 'password_reset'],
            [
                'label' => 'Password reset',
                'category' => EmailTemplateCategory::Notification,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Reset your password — {{brand_name}}',
                'body_html' => self::passwordResetBody(),
                'enabled' => true,
                'sort_order' => 3,
            ],
        );

        return $template->fresh();
    }

    public static function seedBulkSalaryDeclarationTemplate(): EmailTemplate
    {
        return EmailTemplate::query()->updateOrCreate(
            ['slug' => 'bulk_salary_declaration'],
            [
                'label' => 'Bulk salary declaration',
                'category' => EmailTemplateCategory::Document,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Your Salary Declaration from {{company_name}}',
                'body_html' => self::bulkSalaryDeclarationBody(),
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 10,
            ],
        )->fresh();
    }

    public static function seedBulkSalaryCertificateTemplate(): EmailTemplate
    {
        return EmailTemplate::query()->updateOrCreate(
            ['slug' => 'bulk_salary_certificate'],
            [
                'label' => 'Bulk salary certificate',
                'category' => EmailTemplateCategory::Document,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Your Salary Certificate from {{company_name}}',
                'body_html' => self::bulkSalaryCertificateBody(),
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 11,
            ],
        )->fresh();
    }

    private static function bulkSalaryDeclarationBody(): string
    {
        return <<<'TEXT'
Dear {{employee_name}},

Please find your Salary Declaration attached to this email.

We kindly ask you to review the document carefully, sign it according to company standards, and return the signed copy to the HR department at your earliest convenience.

Employee no.: {{employee_no}}

If you have any questions, please contact HR.

Thank you,
{{company_name}}
TEXT;
    }

    private static function bulkSalaryCertificateBody(): string
    {
        return <<<'TEXT'
Dear {{employee_name}},

Please find attached your official Salary Certificate issued by {{company_name}}.

This document certifies your employment and salary details with the company and may be used for official purposes as required.

Employee no.: {{employee_no}}

Should you require any further assistance or an updated certificate, please contact the HR department.

Sincerely,
{{company_name}}
TEXT;
    }

    private static function passwordResetBody(): string
    {
        return <<<'TEXT'
Hello {{user_name}},

You are receiving this email because we received a password reset request for your account.

Click the button below to reset your password:

{{reset_url}}

This password reset link will expire in {{expire_minutes}} minutes.

If you did not request a password reset, no further action is required.
TEXT;
    }
}
