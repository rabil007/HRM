<?php

namespace App\Support\Users;

use App\Models\EmailTemplate;
use App\Models\UserInvitation;
use App\Services\Settings\SettingService;

final class ComposeUserInvitationMail
{
    public const TEMPLATE_SLUG = 'user_invitation';

    /**
     * @return array{
     *     subject: string,
     *     body: string,
     *     companyName: string,
     *     includeCompanyFooter: bool,
     * }
     */
    public function handle(UserInvitation $invitation, string $token): array
    {
        $invitation->loadMissing(['company', 'inviter', 'role']);

        $placeholders = $this->placeholders($invitation, route('invitations.accept', ['token' => $token]));
        $template = EmailTemplate::query()
            ->where('slug', self::TEMPLATE_SLUG)
            ->where('enabled', true)
            ->first();

        $subject = $template !== null
            ? strtr($template->subject, $placeholders)
            : "Invitation to join {$placeholders['{{company_name}}']}";

        $body = $template !== null
            ? strtr($template->body_html, $placeholders)
            : strtr(self::defaultBodyHtml(), $placeholders);

        return [
            'subject' => $subject,
            'body' => $body,
            'companyName' => $placeholders['{{company_name}}'],
            'includeCompanyFooter' => $template?->include_company_footer ?? true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(UserInvitation $invitation, string $acceptUrl): array
    {
        $mailBranding = app(SettingService::class)->mailBranding();
        $companyName = filled($invitation->company?->name)
            ? (string) $invitation->company->name
            : (string) ($mailBranding['brand_name'] ?? config('app.name'));
        $inviteeName = filled($invitation->name) ? (string) $invitation->name : 'there';
        $inviterName = filled($invitation->inviter?->name)
            ? (string) $invitation->inviter->name
            : 'An administrator';
        $roleName = filled($invitation->role?->name) ? (string) $invitation->role->name : '—';
        $expiresAt = $invitation->expires_at?->format('M j, Y') ?? '';

        return [
            '{{invitee_name}}' => $inviteeName,
            '{{inviter_name}}' => $inviterName,
            '{{company_name}}' => $companyName,
            '{{brand_name}}' => (string) ($mailBranding['brand_name'] ?? config('app.name')),
            '{{accept_url}}' => $acceptUrl,
            '{{expires_at}}' => $expiresAt,
            '{{role_name}}' => $roleName,
        ];
    }

    public static function defaultBodyHtml(): string
    {
        return <<<'HTML'
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
    }
}
