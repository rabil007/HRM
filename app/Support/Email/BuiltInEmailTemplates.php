<?php

namespace App\Support\Email;

use App\Enums\EmailTemplateCategory;
use App\Models\EmailTemplate;
use App\Support\Users\ComposeUserInvitationMail;

final class BuiltInEmailTemplates
{
    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $slug): array
    {
        $definitions = self::definitions();

        if (! isset($definitions[$slug])) {
            throw new \InvalidArgumentException("Unknown built-in email template [{$slug}].");
        }

        return $definitions[$slug];
    }

    /**
     * @return array<string, bool>
     */
    public static function uiControls(?string $slug): array
    {
        $defaults = [
            'to_preset' => true,
            'cc_preset' => true,
            'dispatch_at' => false,
            'subject' => true,
            'body' => true,
            'enabled' => true,
            'is_default' => true,
            'include_company_footer' => true,
            'system_layout' => false,
        ];

        return match ($slug) {
            'document_expiry_alert' => [
                ...$defaults,
                'dispatch_at' => true,
                'subject' => false,
                'body' => false,
                'is_default' => false,
                'system_layout' => true,
            ],
            'company_document_expiry_alert' => [
                ...$defaults,
                'to_preset' => false,
                'cc_preset' => false,
                'dispatch_at' => false,
                'subject' => false,
                'body' => false,
                'enabled' => false,
                'is_default' => false,
                'system_layout' => true,
            ],
            default => $defaults,
        };
    }

    /**
     * @return list<string>
     */
    public static function placeholders(string $slug): array
    {
        return self::definition($slug)['placeholders'];
    }

    /**
     * @return list<string>
     */
    public static function findUnsupportedPlaceholders(string $slug, string $subject, string $bodyHtml): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $subject."\n".$bodyHtml, $matches);

        $found = array_values(array_unique($matches[0] ?? []));

        return array_values(array_diff($found, self::placeholders($slug)));
    }

    public static function matchesLegacyDefault(EmailTemplate $template): bool
    {
        foreach (self::definition($template->slug)['legacy_defaults'] as $legacy) {
            if ($template->subject === $legacy['subject'] && $template->body_html === $legacy['body_html']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     category: EmailTemplateCategory,
     *     subject: string,
     *     body_html: string,
     *     enabled: bool,
     *     is_default: bool,
     *     include_company_footer: bool,
     *     sort_order: int,
     *     to_preset: string|null,
     *     cc_preset: string|null,
     *     dispatch_at: string|null,
     *     mark_default_if_none: bool,
     *     placeholders: list<string>,
     *     legacy_defaults: list<array{subject: string, body_html: string}>,
     *     legacy_labels: list<string>
     * }>
     */
    public static function definitions(): array
    {
        return [
            'document_share' => self::documentShare(),
            'document_expiry_alert' => self::documentExpiryAlert(),
            'company_document_expiry_alert' => self::companyDocumentExpiryAlert(),
            'payslip_delivery' => self::payslipDelivery(),
            'leave_request_submitted' => self::leaveRequestSubmitted(),
            'leave_request_updated' => self::leaveRequestUpdated(),
            'leave_request_approver_action_required' => self::leaveRequestApproverActionRequired(),
            'leave_request_notification_only' => self::leaveRequestNotificationOnly(),
            'leave_request_approved' => self::leaveRequestApproved(),
            'leave_request_rejected' => self::leaveRequestRejected(),
            'password_reset' => self::passwordReset(),
            'user_invitation' => self::userInvitation(),
            'bulk_salary_declaration' => self::bulkSalaryDeclaration(),
            'bulk_salary_declaration_sign_reminder' => self::bulkSalaryDeclarationSignReminder(),
            'bulk_salary_certificate' => self::bulkSalaryCertificate(),
            'crew_operational_alert_digest' => self::crewOperationalAlertDigest(),
            'document_recipient_action_request' => self::documentRecipientActionRequest(),
            'document_recipient_action_reminder' => self::documentRecipientActionReminder(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function leavePlaceholders(): array
    {
        return [
            '{{employee_name}}',
            '{{employee_no}}',
            '{{department_name}}',
            '{{leave_type}}',
            '{{start_date}}',
            '{{end_date}}',
            '{{total_days}}',
            '{{reason}}',
            '{{manager_name}}',
            '{{company_name}}',
            '{{request_url}}',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function base(array $overrides): array
    {
        return [
            'enabled' => true,
            'is_default' => false,
            'include_company_footer' => true,
            'to_preset' => null,
            'cc_preset' => null,
            'dispatch_at' => null,
            'mark_default_if_none' => false,
            'placeholders' => [],
            'legacy_defaults' => [],
            'legacy_labels' => [],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentShare(): array
    {
        $subject = 'Documents attached';
        $body = <<<'TEXT'
Dear recipient,

Please find the requested documents attached to this email.

If you have any questions regarding these documents, please contact the sender or the relevant HR representative.

Kind regards
TEXT;

        return self::base([
            'label' => 'Document share',
            'category' => EmailTemplateCategory::Document,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 0,
            'mark_default_if_none' => true,
            'legacy_defaults' => [
                [
                    'subject' => 'Documents from Overseas Marine Services',
                    'body_html' => "Hello,\n\nPlease find the attached employee documents.\n\nThank you.",
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentExpiryAlert(): array
    {
        return self::base([
            'label' => 'Employee document expiry alert',
            'category' => EmailTemplateCategory::Notification,
            'subject' => 'Employee Document Expiry Alert',
            'body_html' => 'System-generated employee document expiry compliance summary. Recipients and dispatch time are configured on this template. The email layout and table content are system-managed.',
            'sort_order' => 0,
            'legacy_labels' => ['Document expiry alert'],
            'legacy_defaults' => [
                [
                    'subject' => 'Document Expiry Alert',
                    'body_html' => 'Automated expiry summary email.',
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function companyDocumentExpiryAlert(): array
    {
        return self::base([
            'label' => 'Company document expiry alert',
            'category' => EmailTemplateCategory::Notification,
            'subject' => 'Company Document Expiry Alert',
            'body_html' => 'System-generated company document expiry compliance summary. Recipients and delivery status are configured per company under Company Documents → Expiry Notification Settings. The email layout and table content are system-managed.',
            'sort_order' => 1,
            'legacy_defaults' => [
                [
                    'subject' => 'Company Document Expiry Alert',
                    'body_html' => 'Automated company document expiry summary email.',
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payslipDelivery(): array
    {
        $subject = 'Your payslip for {{period_name}} — {{company_name}}';
        $body = <<<'TEXT'
Dear {{employee_name}},

Please find your payslip for {{period_name}} attached.

Employee No.: {{employee_no}}
Net Salary: {{net_salary}}

Please review the payslip and contact HR/Payroll if you have any questions or notice any discrepancy.

Kind regards,
{{company_name}}
TEXT;

        return self::base([
            'label' => 'Payslip delivery',
            'category' => EmailTemplateCategory::Payroll,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 0,
            'mark_default_if_none' => true,
            'placeholders' => [
                '{{employee_name}}',
                '{{employee_no}}',
                '{{period_name}}',
                '{{net_salary}}',
                '{{company_name}}',
            ],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
Dear {{employee_name}},

Please find your payslip for {{period_name}} attached to this email.

Employee no.: {{employee_no}}
Net salary: {{net_salary}}

If you have any questions about your payslip, please contact HR.

Thank you,
{{company_name}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function leaveRequestSubmitted(): array
    {
        $subject = 'New leave request — {{employee_name}} ({{leave_type}})';
        $body = <<<'TEXT'
A new leave request requires your review.

Employee: {{employee_name}}
Employee no.: {{employee_no}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}

Please review the request and take the required action.
TEXT;

        return self::base([
            'label' => 'Leave request submitted',
            'category' => EmailTemplateCategory::Hr,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 0,
            'mark_default_if_none' => true,
            'placeholders' => self::leavePlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
A new leave request has been submitted and is pending your review.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function leaveRequestUpdated(): array
    {
        $subject = 'Leave request updated — {{employee_name}} ({{leave_type}})';
        $body = <<<'TEXT'
A leave request assigned to you has been updated and still requires your review.

Employee: {{employee_name}}
Employee no.: {{employee_no}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}

Please review the updated information before taking action.
TEXT;

        return self::base([
            'label' => 'Leave request updated',
            'category' => EmailTemplateCategory::Hr,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 1,
            'placeholders' => self::leavePlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
A leave request assigned to you was edited and still requires your approval.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function leaveRequestApproverActionRequired(): array
    {
        $subject = 'Leave approval required — {{employee_name}} ({{leave_type}})';
        $body = <<<'TEXT'
A leave request now requires your approval.

Employee: {{employee_name}}
Employee no.: {{employee_no}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}

Please review the request and approve or decline it as appropriate.
TEXT;

        return self::base([
            'label' => 'Leave request approver action required',
            'category' => EmailTemplateCategory::Hr,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 2,
            'placeholders' => self::leavePlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
A leave request now requires your approval.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function leaveRequestNotificationOnly(): array
    {
        $subject = 'Leave request submitted for your information — {{employee_name}} ({{leave_type}})';
        $body = <<<'TEXT'
A leave request has been submitted for your information.

No approval or action is required from you.

Employee: {{employee_name}}
Employee no.: {{employee_no}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}
TEXT;

        return self::base([
            'label' => 'Leave request notification only',
            'category' => EmailTemplateCategory::Hr,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 3,
            'placeholders' => self::leavePlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
A leave request has been submitted for your information. No approval is required from you.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}
Reason: {{reason}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function leaveRequestApproved(): array
    {
        $subject = 'Leave request approved — {{leave_type}}';
        $body = <<<'TEXT'
Dear {{employee_name}},

Your leave request has been approved.

Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
Total days: {{total_days}}

Please ensure any required handover or operational arrangements are completed before your leave begins.
TEXT;

        return self::base([
            'label' => 'Leave request approved',
            'category' => EmailTemplateCategory::Hr,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 4,
            'placeholders' => self::leavePlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
Your leave request has been approved.

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function leaveRequestRejected(): array
    {
        $subject = 'Leave request declined — {{leave_type}}';
        $body = <<<'TEXT'
Dear {{employee_name}},

Your leave request has not been approved.

Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}

Reason:
{{rejection_reason}}

If you need clarification, please contact your approver or HR.
TEXT;

        $placeholders = [
            ...self::leavePlaceholders(),
            '{{rejection_reason}}',
        ];

        return self::base([
            'label' => 'Leave request declined',
            'category' => EmailTemplateCategory::Hr,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 5,
            'placeholders' => $placeholders,
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
Your leave request has been declined.

Reason for decline: {{rejection_reason}}

Employee: {{employee_name}}
Leave type: {{leave_type}}
Dates: {{start_date}} to {{end_date}}
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function passwordReset(): array
    {
        $subject = 'Reset your password — {{brand_name}}';
        $body = <<<'TEXT'
Dear {{user_name}},

We received a request to reset the password for your account.

Use the secure button below to choose a new password:

{{reset_url}}

This password reset link will expire in {{expire_minutes}} minutes.

If you did not request a password reset, no action is required. For security, do not forward this email or share the reset link.
TEXT;

        return self::base([
            'label' => 'Password reset',
            'category' => EmailTemplateCategory::Notification,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 3,
            'placeholders' => [
                '{{user_name}}',
                '{{reset_url}}',
                '{{expire_minutes}}',
                '{{brand_name}}',
            ],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'TEXT'
Hello {{user_name}},

You are receiving this email because we received a password reset request for your account.

Click the button below to reset your password:

{{reset_url}}

This password reset link will expire in {{expire_minutes}} minutes.

If you did not request a password reset, no further action is required.
TEXT,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function userInvitation(): array
    {
        $subject = 'Invitation to join {{company_name}}';
        $legacyBody = <<<'HTML'
<p style="margin:0 0 16px;">Hello {{invitee_name}},</p>
<p style="margin:0 0 16px;">{{inviter_name}} has invited you to join <strong>{{company_name}}</strong> on {{brand_name}}.</p>
<p style="margin:0 0 16px;">Use the button below to accept this invitation:</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{accept_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Accept invitation
            </a>
        </td>
    </tr>
</table>
<p style="margin:0 0 16px;">If you already have an account, sign in with your existing credentials after clicking the button. If you are new, you will be guided through account setup and password creation.</p>
<p style="margin:0 0 16px;">This invitation link will expire on {{expires_at}}.</p>
<p style="margin:0;">Thank you,<br>{{company_name}}</p>
HTML;

        return self::base([
            'label' => 'User invitation',
            'category' => EmailTemplateCategory::Notification,
            'subject' => $subject,
            'body_html' => ComposeUserInvitationMail::defaultBodyHtml(),
            'sort_order' => 4,
            'placeholders' => [
                '{{invitee_name}}',
                '{{inviter_name}}',
                '{{company_name}}',
                '{{brand_name}}',
                '{{accept_url}}',
                '{{expires_at}}',
                '{{role_name}}',
            ],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => $legacyBody,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bulkSalaryDeclaration(): array
    {
        $subject = 'Your Salary Declaration from {{company_name}}';
        $body = <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">Please review and sign your Salary Declaration from {{company_name}}.</p>
<p style="margin:0 0 16px;"><strong>Employee No.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">Use the secure button below to complete the electronic signature. You may also download the attached PDF, sign it manually, and return the signed copy to HR.</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{signature_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Sign declaration
            </a>
        </td>
    </tr>
</table>
<p style="margin:0 0 16px;">If you have any questions, please contact HR.</p>
<p style="margin:0;">Kind regards,<br>{{company_name}}</p>
HTML;

        return self::base([
            'label' => 'Salary declaration',
            'category' => EmailTemplateCategory::Document,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 10,
            'placeholders' => self::bulkDocumentPlaceholders(),
            'legacy_labels' => ['Bulk salary declaration'],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'HTML'
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
HTML,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bulkSalaryDeclarationSignReminder(): array
    {
        $subject = 'Reminder: please sign your Salary Declaration from {{company_name}}';
        $body = <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">This is a reminder that your Salary Declaration is still awaiting your signature.</p>
<p style="margin:0 0 16px;"><strong>Employee No.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">Use the button below to complete the electronic signature. If you have already signed the document, please disregard this reminder.</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{signature_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Sign declaration
            </a>
        </td>
    </tr>
</table>
<p style="margin:0;">Kind regards,<br>{{company_name}}</p>
HTML;

        return self::base([
            'label' => 'Salary declaration reminder',
            'category' => EmailTemplateCategory::Document,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 12,
            'placeholders' => self::bulkDocumentPlaceholders(),
            'legacy_labels' => ['Bulk salary declaration sign reminder'],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'HTML'
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
HTML,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bulkSalaryCertificate(): array
    {
        $subject = 'Your Salary Certificate from {{company_name}}';
        $body = <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">Please find your requested Salary Certificate attached.</p>
<p style="margin:0 0 16px;"><strong>Employee No.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">Please contact HR if any information in the certificate requires clarification.</p>
<p style="margin:0;">Kind regards,<br>{{company_name}}</p>
HTML;

        return self::base([
            'label' => 'Salary certificate',
            'category' => EmailTemplateCategory::Document,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 11,
            'placeholders' => self::bulkDocumentPlaceholders(),
            'legacy_labels' => ['Bulk salary certificate'],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'HTML'
<p style="margin:0 0 16px;">Dear {{employee_name}},</p>
<p style="margin:0 0 16px;">Please find attached your official Salary Certificate issued by {{company_name}}.</p>
<p style="margin:0 0 16px;">This document certifies your employment and salary details with the company and may be used for official purposes as required.</p>
<p style="margin:0 0 16px;"><strong>Employee no.:</strong> {{employee_no}}</p>
<p style="margin:0 0 16px;">Should you require any further assistance or an updated certificate, please contact the HR department.</p>
<p style="margin:0;">Sincerely,<br>{{company_name}}</p>
HTML,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function crewOperationalAlertDigest(): array
    {
        $subject = 'Crew Operations — {{alert_count}} items require attention';
        $body = <<<'HTML'
<p style="margin:0 0 16px;">Crew Operations requires attention.</p>
<p style="margin:0 0 16px;">{{alert_count}} items require attention. Please open OMS-HRM for the latest assignment and movement information before taking action.</p>
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

        return self::base([
            'label' => 'Crew Operations alert digest',
            'category' => EmailTemplateCategory::Notification,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 5,
            'placeholders' => [
                '{{company_name}}',
                '{{alert_count}}',
                '{{generated_at}}',
                '{{highest_severity}}',
                '{{alerts_table}}',
                '{{crew_operations_url}}',
            ],
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'HTML'
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
HTML,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentRecipientActionRequest(): array
    {
        $subject = '{{action_label}} required — {{document_title}}';
        $body = <<<'HTML'
<p style="margin:0 0 16px;">Dear {{recipient_name}},</p>
<p style="margin:0 0 16px;">A document from {{company_name}} requires your review and action.</p>
<p style="margin:0 0 8px;"><strong>Document:</strong> {{document_title}}</p>
<p style="margin:0 0 8px;"><strong>Employee:</strong> {{employee_name}} ({{employee_no}})</p>
<p style="margin:0 0 8px;"><strong>Action:</strong> {{action_label}}</p>
<p style="margin:0 0 8px;"><strong>Step:</strong> {{step_label}}</p>
<p style="margin:0 0 16px;"><strong>Expires:</strong> {{expires_at}}</p>
<p style="margin:0 0 16px;">Please use the secure button below to open the document and complete the requested action. For your security, do not forward this email or share the access link.</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{action_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Open document action
            </a>
        </td>
    </tr>
</table>
<p style="margin:0;color:#6b7280;font-size:12px;">If you were not expecting this message, contact {{company_name}} HR.</p>
HTML;

        return self::base([
            'label' => 'Document action request',
            'category' => EmailTemplateCategory::Document,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 20,
            'placeholders' => self::documentRecipientPlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'HTML'
<p style="margin:0 0 16px;">Dear {{recipient_name}},</p>
<p style="margin:0 0 16px;">A document action is required for <strong>{{document_title}}</strong> ({{employee_name}} / {{employee_no}}).</p>
<p style="margin:0 0 8px;"><strong>Action:</strong> {{action_label}}</p>
<p style="margin:0 0 8px;"><strong>Step:</strong> {{step_label}}</p>
<p style="margin:0 0 16px;"><strong>Expires:</strong> {{expires_at}}</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{action_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                Open document action
            </a>
        </td>
    </tr>
</table>
<p style="margin:0;color:#6b7280;font-size:12px;">If you were not expecting this message, contact {{company_name}} HR.</p>
HTML,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentRecipientActionReminder(): array
    {
        $subject = 'Reminder: {{action_label}} — {{document_title}}';
        $body = <<<'HTML'
<p style="margin:0 0 16px;">Dear {{recipient_name}},</p>
<p style="margin:0 0 16px;">This is a reminder that a document from {{company_name}} is still awaiting your action.</p>
<p style="margin:0 0 8px;"><strong>Document:</strong> {{document_title}}</p>
<p style="margin:0 0 8px;"><strong>Employee:</strong> {{employee_name}} ({{employee_no}})</p>
<p style="margin:0 0 8px;"><strong>Action:</strong> {{action_label}}</p>
<p style="margin:0 0 8px;"><strong>Step:</strong> {{step_label}}</p>
<p style="margin:0 0 8px;"><strong>Days remaining:</strong> {{days_remaining}}</p>
<p style="margin:0 0 16px;">Please use the secure button below to continue before <strong>{{expires_at}}</strong>. If you have already completed the requested action, no further action is required.</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{action_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                {{action_label}}
            </a>
        </td>
    </tr>
</table>
<p style="margin:0;color:#6b7280;font-size:12px;">If you were not expecting this message, contact {{company_name}} HR.</p>
HTML;

        return self::base([
            'label' => 'Document action reminder',
            'category' => EmailTemplateCategory::Document,
            'subject' => $subject,
            'body_html' => $body,
            'sort_order' => 21,
            'placeholders' => self::documentRecipientPlaceholders(),
            'legacy_defaults' => [
                [
                    'subject' => $subject,
                    'body_html' => <<<'HTML'
<p style="margin:0 0 16px;">Hello {{recipient_name}},</p>
<p style="margin:0 0 16px;">This is a reminder that action is still required for <strong>{{document_title}}</strong> ({{employee_name}} / {{employee_no}}).</p>
<p style="margin:0 0 8px;"><strong>Action:</strong> {{action_label}}</p>
<p style="margin:0 0 8px;"><strong>Step:</strong> {{step_label}}</p>
<p style="margin:0 0 8px;"><strong>Days remaining:</strong> {{days_remaining}}</p>
<p style="margin:0 0 16px;">Please complete it before <strong>{{expires_at}}</strong>.</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
    <tr>
        <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
            <a href="{{action_url}}" class="email-btn-link" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                {{action_label}}
            </a>
        </td>
    </tr>
</table>
<p style="margin:0;color:#6b7280;font-size:12px;">If you were not expecting this message, contact {{company_name}} HR.</p>
HTML,
                ],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function bulkDocumentPlaceholders(): array
    {
        return [
            '{{employee_name}}',
            '{{employee_no}}',
            '{{company_name}}',
            '{{document_type}}',
            '{{signature_url}}',
        ];
    }

    /**
     * @return list<string>
     */
    private static function documentRecipientPlaceholders(): array
    {
        return [
            '{{company_name}}',
            '{{recipient_name}}',
            '{{employee_name}}',
            '{{employee_no}}',
            '{{document_title}}',
            '{{document_type}}',
            '{{action_label}}',
            '{{action_url}}',
            '{{expires_at}}',
            '{{step_label}}',
            '{{days_remaining}}',
        ];
    }
}
