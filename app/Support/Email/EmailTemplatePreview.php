<?php

namespace App\Support\Email;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Services\Settings\SettingService;
use Illuminate\Support\Facades\View;

final class EmailTemplatePreview
{
    /**
     * @return array{subject: string, html: string}
     */
    public function render(EmailTemplate $template, ?int $companyId = null): array
    {
        return $this->renderFromStrings(
            slug: $template->slug,
            subject: $template->subject,
            bodyHtml: $template->body_html,
            companyId: $companyId,
            includeCompanyFooter: $template->include_company_footer,
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function renderFromStrings(
        string $slug,
        string $subject,
        string $bodyHtml,
        ?int $companyId = null,
        bool $includeCompanyFooter = true,
    ): array {
        $organizationName = $this->resolveOrganizationName($companyId);
        $placeholders = $this->samplePlaceholders($organizationName);
        $renderedSubject = $this->applyPlaceholders($subject, $placeholders);
        $renderedBody = $this->applyPlaceholders($bodyHtml, $placeholders);

        $html = match ($slug) {
            'leave_request_submitted' => $this->renderLeaveRequestSubmitted(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'document_expiry_alert' => $this->renderDocumentExpiryAlert($organizationName, $includeCompanyFooter),
            'document_share' => $this->renderDocumentShare($organizationName, $renderedSubject, $renderedBody, $includeCompanyFooter),
            default => $this->renderPlainPreview($renderedSubject, $renderedBody, $includeCompanyFooter),
        };

        return [
            'subject' => $renderedSubject,
            'html' => $html,
        ];
    }

    private function renderLeaveRequestSubmitted(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.leave-request-submitted', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'employeeName' => $placeholders['{{employee_name}}'],
            'employeeNo' => $placeholders['{{employee_no}}'],
            'departmentName' => $placeholders['{{department_name}}'],
            'managerName' => $placeholders['{{manager_name}}'],
            'leaveType' => $placeholders['{{leave_type}}'],
            'leaveTypeColor' => '#8b5cf6',
            'startDate' => $placeholders['{{start_date}}'],
            'endDate' => $placeholders['{{end_date}}'],
            'totalDays' => $placeholders['{{total_days}}'],
            'reason' => $placeholders['{{reason}}'],
            'requestUrl' => $placeholders['{{request_url}}'],
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    private function renderDocumentExpiryAlert(string $organizationName, bool $includeCompanyFooter): string
    {
        return View::make('mail.document-expiry-alert', [
            'organizationName' => $organizationName,
            'includeCompanyFooter' => $includeCompanyFooter,
            'alertWindowDays' => 30,
            'rows' => [
                [
                    'employee_name' => 'Jane Smith',
                    'employee_id' => 'EMP-1042',
                    'document_name' => 'Passport',
                    'expiry_date' => now()->addDays(12)->format('d M Y'),
                    'days_remaining' => 12,
                ],
                [
                    'employee_name' => 'Ahmed Khan',
                    'employee_id' => 'EMP-0871',
                    'document_name' => 'Seaman Book',
                    'expiry_date' => now()->addDays(24)->format('d M Y'),
                    'days_remaining' => 24,
                ],
            ],
        ])->render();
    }

    private function renderDocumentShare(
        string $organizationName,
        string $subject,
        string $body,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.documents-shared', [
            'organizationName' => $organizationName,
            'senderName' => 'HR Team',
            'subjectLine' => $subject,
            'bodyMessage' => $body,
            'includeCompanyFooter' => $includeCompanyFooter,
            'attachmentSummaries' => [
                ['name' => 'Passport.pdf', 'size_bytes' => 245_760],
                ['name' => 'Visa.pdf', 'size_bytes' => 184_320],
            ],
        ])->render();
    }

    private function renderPlainPreview(string $subject, string $body, bool $includeCompanyFooter): string
    {
        return View::make('mail.email-template-plain-preview', [
            'subjectLine' => $subject,
            'bodyMessage' => $body,
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @return array<string, string>
     */
    private function samplePlaceholders(string $organizationName): array
    {
        return [
            '{{employee_name}}' => 'Jane Smith',
            '{{employee_no}}' => 'EMP-1042',
            '{{department_name}}' => 'Technology',
            '{{leave_type}}' => 'Annual Leave',
            '{{start_date}}' => now()->addDays(7)->format('d M Y'),
            '{{end_date}}' => now()->addDays(9)->format('d M Y'),
            '{{total_days}}' => '3.0',
            '{{reason}}' => 'Family commitment',
            '{{manager_name}}' => 'John Manager',
            '{{company_name}}' => $organizationName,
            '{{request_url}}' => url('/attendance/leave-requests/1'),
            '{{period_name}}' => now()->format('F Y'),
            '{{net_salary}}' => '12,500.00',
        ];
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function applyPlaceholders(string $template, array $placeholders): string
    {
        return strtr($template, $placeholders);
    }

    private function resolveOrganizationName(?int $companyId): string
    {
        if ($companyId !== null) {
            $companyName = Company::query()->whereKey($companyId)->value('name');

            if (filled($companyName)) {
                return (string) $companyName;
            }
        }

        $branding = app(SettingService::class)->mailBranding();

        return (string) ($branding['brand_name'] ?? config('app.name'));
    }
}
