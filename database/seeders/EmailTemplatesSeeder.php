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
        self::seedLeaveRequestUpdatedTemplate();
        self::seedLeaveRequestApproverActionRequiredTemplate();
        self::seedLeaveRequestApprovedTemplate();
        self::seedLeaveRequestRejectedTemplate();
        self::seedPasswordResetTemplate();
        self::seedBulkSalaryDeclarationTemplate();
        self::seedBulkSalaryDeclarationSignReminderTemplate();
        self::seedBulkSalaryCertificateTemplate();
        self::seedCrewOperationalAlertDigestTemplate();
    }

    /**
     * Create missing leave email templates without overwriting administrator customizations.
     * Soft-deleted matching slugs are restored without clobbering custom content.
     *
     * @param  array<string, mixed>  $defaults
     * @param  bool  $markDefaultIfNone  Only for brand-new rows when no HR default exists
     */
    private static function seedLeaveTemplateIfMissing(
        string $slug,
        array $defaults,
        bool $markDefaultIfNone = false,
    ): EmailTemplate {
        $existing = EmailTemplate::withTrashed()->where('slug', $slug)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing->fresh() ?? $existing;
        }

        $template = EmailTemplate::query()->create([
            'slug' => $slug,
            ...$defaults,
        ]);

        if (
            $markDefaultIfNone
            && ! EmailTemplate::query()
                ->where('category', EmailTemplateCategory::Hr)
                ->where('is_default', true)
                ->whereKeyNot($template->id)
                ->exists()
        ) {
            $template->markAsDefaultForCategory();
        }

        return $template->fresh() ?? $template;
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
        return self::seedLeaveTemplateIfMissing('leave_request_submitted', [
            'label' => 'Leave request submitted',
            'category' => EmailTemplateCategory::Hr,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'subject' => 'New leave request — {{employee_name}} ({{leave_type}})',
            'body_html' => self::leaveRequestSubmittedBody(),
            'enabled' => true,
            'sort_order' => 0,
        ], markDefaultIfNone: true);
    }

    public static function seedLeaveRequestUpdatedTemplate(): EmailTemplate
    {
        return self::seedLeaveTemplateIfMissing('leave_request_updated', [
            'label' => 'Leave request updated',
            'category' => EmailTemplateCategory::Hr,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'subject' => 'Leave request updated — {{employee_name}} ({{leave_type}})',
            'body_html' => self::leaveRequestUpdatedBody(),
            'enabled' => true,
            'sort_order' => 1,
        ]);
    }

    public static function seedLeaveRequestApproverActionRequiredTemplate(): EmailTemplate
    {
        return self::seedLeaveTemplateIfMissing('leave_request_approver_action_required', [
            'label' => 'Leave request approver action required',
            'category' => EmailTemplateCategory::Hr,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'subject' => 'Leave approval required — {{employee_name}} ({{leave_type}})',
            'body_html' => self::leaveRequestApproverActionRequiredBody(),
            'enabled' => true,
            'sort_order' => 2,
        ]);
    }

    public static function seedLeaveRequestApprovedTemplate(): EmailTemplate
    {
        return self::seedLeaveTemplateIfMissing('leave_request_approved', [
            'label' => 'Leave request approved',
            'category' => EmailTemplateCategory::Hr,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'subject' => 'Leave request approved — {{leave_type}}',
            'body_html' => self::leaveRequestApprovedBody(),
            'enabled' => true,
            'sort_order' => 3,
        ]);
    }

    public static function seedLeaveRequestRejectedTemplate(): EmailTemplate
    {
        return self::seedLeaveTemplateIfMissing('leave_request_rejected', [
            'label' => 'Leave request declined',
            'category' => EmailTemplateCategory::Hr,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'subject' => 'Leave request declined — {{leave_type}}',
            'body_html' => self::leaveRequestRejectedBody(),
            'enabled' => true,
            'sort_order' => 4,
        ]);
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

    private static function leaveRequestUpdatedBody(): string
    {
        return <<<'TEXT'
A leave request assigned to you was edited and still requires your approval.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}
TEXT;
    }

    private static function leaveRequestApproverActionRequiredBody(): string
    {
        return <<<'TEXT'
A leave request now requires your approval.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}
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

    public static function seedBulkSalaryDeclarationSignReminderTemplate(): EmailTemplate
    {
        return EmailTemplate::query()->updateOrCreate(
            ['slug' => 'bulk_salary_declaration_sign_reminder'],
            [
                'label' => 'Bulk salary declaration sign reminder',
                'category' => EmailTemplateCategory::Document,
                'to_preset' => null,
                'cc_preset' => null,
                'dispatch_at' => null,
                'subject' => 'Reminder: please sign your Salary Declaration from {{company_name}}',
                'body_html' => self::bulkSalaryDeclarationSignReminderBody(),
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 12,
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
        return <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">Please find your Salary Declaration attached to this email.</p>
<p style="margin:0 0 16px;">You may sign electronically using the button below:</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{signature_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Sign declaration
            </a>
        </td>
    </tr>
</table>
<p style="margin:0 0 16px;">Alternatively, download the attached PDF, sign it manually, and return the signed copy to HR.</p>
<p style="margin:0 0 16px;">We kindly ask you to review the document carefully, sign it according to company standards, and return the signed copy to the HR department at your earliest convenience.</p>
<p style="margin:0 0 16px;"><strong>Employee no.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">If you have any questions, please contact HR.</p>
<p style="margin:0;">Thank you,<br>{{company_name}}</p>
HTML;
    }

    private static function bulkSalaryDeclarationSignReminderBody(): string
    {
        return <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">This is a friendly reminder that your Salary Declaration from {{company_name}} is still awaiting your signature.</p>
<p style="margin:0 0 16px;">If you missed the earlier email or forgot to sign, you can complete the electronic signature using the button below:</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{signature_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Sign declaration
            </a>
        </td>
    </tr>
</table>
<p style="margin:0 0 16px;">Alternatively, download the attached PDF, sign it manually, and return the signed copy to HR.</p>
<p style="margin:0 0 16px;"><strong>Employee no.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">If you have already signed, please disregard this reminder. For any questions, contact HR.</p>
<p style="margin:0;">Thank you,<br>{{company_name}}</p>
HTML;
    }

    private static function bulkSalaryCertificateBody(): string
    {
        return <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">Please find attached your official Salary Certificate issued by {{company_name}}.</p>
<p style="margin:0 0 16px;">This document certifies your employment and salary details with the company and may be used for official purposes as required.</p>
<p style="margin:0 0 16px;"><strong>Employee no.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">Should you require any further assistance or an updated certificate, please contact the HR department.</p>
<p style="margin:0;">Sincerely,<br>{{company_name}}</p>
HTML;
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

    public static function seedCrewOperationalAlertDigestTemplate(): EmailTemplate
    {
        $existing = EmailTemplate::withTrashed()->where('slug', 'crew_operational_alert_digest')->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing->fresh() ?? $existing;
        }

        return EmailTemplate::query()->create([
            'slug' => 'crew_operational_alert_digest',
            'label' => 'Crew Operations alert digest',
            'category' => EmailTemplateCategory::Notification,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'subject' => 'Crew Operations — {{alert_count}} items require attention',
            'body_html' => self::crewOperationalAlertDigestBody(),
            'enabled' => true,
            'include_company_footer' => true,
            'is_default' => false,
            'sort_order' => 5,
        ])->fresh();
    }

    private static function crewOperationalAlertDigestBody(): string
    {
        return <<<'HTML'
<p style="margin:0 0 16px;"><strong>Crew Operations Alert Summary</strong></p>
<p style="margin:0 0 16px;">{{alert_count}} items require attention.</p>
<p style="margin:0 0 16px;color:#6b7280;font-size:13px;">Generated: {{generated_at}}</p>
<div style="margin:0 0 24px;">
{{alerts_table}}
</div>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{crew_operations_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Open Crew Operations
            </a>
        </td>
    </tr>
</table>
<p style="margin:0;color:#6b7280;font-size:12px;">You are receiving this message because you are configured as a Crew Operations notification recipient.</p>
HTML;
    }
}
