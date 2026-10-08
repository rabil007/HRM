<?php

namespace App\Support\Email;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Services\Settings\SettingService;
use App\Support\CrewOperations\CrewOperationalAlertDigestPresenter;
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
        $renderedSubject = match ($slug) {
            'document_expiry_alert' => 'Employee Document Expiry Alert — 2 document(s) require attention',
            'company_document_expiry_alert' => 'Company Document Expiry Alert — 2 document(s) require attention',
            default => $this->applyPlaceholders($subject, $placeholders),
        };
        $renderedBody = $this->applyPlaceholders($bodyHtml, $placeholders);

        $html = match ($slug) {
            'leave_request_submitted' => $this->renderLeaveRequestSubmitted(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'leave_request_approved' => $this->renderLeaveRequestDecided(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                status: 'approved',
                rejectionReason: null,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'leave_request_rejected' => $this->renderLeaveRequestDecided(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                status: 'rejected',
                rejectionReason: $placeholders['{{rejection_reason}}'],
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'document_expiry_alert' => $this->renderDocumentExpiryAlert($organizationName, $includeCompanyFooter),
            'company_document_expiry_alert' => $this->renderCompanyDocumentExpiryAlert($organizationName, $includeCompanyFooter),
            'document_share' => $this->renderDocumentShare($organizationName, $renderedSubject, $renderedBody, $includeCompanyFooter),
            'crew_operational_alert_digest' => $this->renderCrewOperationalAlertDigest(
                subject: $renderedSubject,
                organizationName: $organizationName,
                bodyHtml: $renderedBody,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'document_recipient_action_request' => $this->renderDocumentRecipientActionRequest(
                subject: $renderedSubject,
                organizationName: $organizationName,
                bodyHtml: $renderedBody,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'document_recipient_action_reminder' => $this->renderDocumentRecipientActionRequest(
                subject: $renderedSubject,
                organizationName: $organizationName,
                bodyHtml: $renderedBody,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'user_invitation' => $this->renderUserInvitation(
                subject: $renderedSubject,
                organizationName: $organizationName,
                bodyHtml: $renderedBody,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'requirement_submitted_for_approval',
            'requirement_assigned_for_approval' => $this->renderRequirementSubmitted(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                heading: $slug === 'requirement_assigned_for_approval'
                    ? 'Requirement assigned for approval'
                    : 'Requirement pending approval',
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'requirement_approved' => $this->renderRequirementApproved(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'requirement_returned' => $this->renderRequirementReturned(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'requirement_target_date_three_days_before',
            'requirement_target_date_due_today' => $this->renderRequirementTargetDateReminder(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'requirement_deadline_extension_requested',
            'requirement_deadline_extension_approved',
            'requirement_deadline_extension_rejected',
            'requirement_deadline_extended_by_requester' => $this->renderRequirementDeadlineExtension(
                slug: $slug,
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'requirement_headcount_revision_requested',
            'requirement_headcount_revision_approved',
            'requirement_headcount_revision_rejected' => $this->renderRequirementHeadcountRevision(
                slug: $slug,
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
            'crew_movement_correction_decided' => $this->renderCrewMovementCorrectionDecided(
                subject: $renderedSubject,
                organizationName: $organizationName,
                introMessage: trim($renderedBody),
                placeholders: $placeholders,
                includeCompanyFooter: $includeCompanyFooter,
            ),
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
            'approvalLabel' => 'Current approver',
            'approvalNames' => array_values(array_filter([
                (string) ($placeholders['{{approver_name}}'] ?? $placeholders['{{manager_name}}'] ?? ''),
            ])),
            'approvalHelpText' => null,
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

    private function renderLeaveRequestDecided(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        string $status,
        ?string $rejectionReason,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.leave-request-decided', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'employeeName' => $placeholders['{{employee_name}}'],
            'employeeNo' => $placeholders['{{employee_no}}'],
            'departmentName' => $placeholders['{{department_name}}'],
            'decidedByName' => (string) ($placeholders['{{approver_name}}'] ?? $placeholders['{{manager_name}}'] ?? ''),
            'leaveType' => $placeholders['{{leave_type}}'],
            'leaveTypeColor' => '#8b5cf6',
            'startDate' => $placeholders['{{start_date}}'],
            'endDate' => $placeholders['{{end_date}}'],
            'totalDays' => $placeholders['{{total_days}}'],
            'reason' => $placeholders['{{reason}}'],
            'requestUrl' => $placeholders['{{request_url}}'],
            'status' => $status,
            'rejectionReason' => $rejectionReason,
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    private function renderDocumentExpiryAlert(string $organizationName, bool $includeCompanyFooter): string
    {
        return View::make('mail.document-expiry-alert', [
            'organizationName' => $organizationName,
            'includeCompanyFooter' => $includeCompanyFooter,
            'alertWindowDays' => 30,
            'complianceUrl' => url('/organization/documents'),
            'rows' => [
                [
                    'employee_name' => 'John Doe',
                    'employee_id' => '1042',
                    'document_name' => 'Passport',
                    'expiry_date' => now()->addDays(24)->format('d M Y'),
                    'days_remaining' => 24,
                    'folder_url' => url('/organization/documents/employees/1'),
                ],
                [
                    'employee_name' => 'Jane Doe',
                    'employee_id' => '1077',
                    'document_name' => 'Visa',
                    'expiry_date' => now()->addDays(7)->format('d M Y'),
                    'days_remaining' => 7,
                    'folder_url' => url('/organization/documents/employees/2'),
                ],
            ],
        ])->render();
    }

    private function renderCompanyDocumentExpiryAlert(string $organizationName, bool $includeCompanyFooter): string
    {
        return View::make('mail.company-document-expiry-alert', [
            'organizationName' => $organizationName,
            'includeCompanyFooter' => $includeCompanyFooter,
            'alertWindowDays' => 30,
            'complianceUrl' => url('/organization/companies/1/documents'),
            'rows' => [
                [
                    'document_name' => 'Trade License',
                    'document_number' => 'TL-2026-001',
                    'expiry_date' => now()->addDays(20)->format('d M Y'),
                    'days_remaining' => 20,
                    'view_url' => url('/organization/companies/1/documents'),
                ],
                [
                    'document_name' => 'Establishment Card',
                    'document_number' => 'EC-5582',
                    'expiry_date' => now()->addDays(5)->format('d M Y'),
                    'days_remaining' => 5,
                    'view_url' => url('/organization/companies/1/documents'),
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
            '{{approver_name}}' => 'Sara Approver',
            '{{approver_names}}' => 'Sara Approver',
            '{{company_name}}' => $organizationName,
            '{{request_url}}' => url('/attendance/leave-requests/1'),
            '{{period_name}}' => now()->format('F Y'),
            '{{net_salary}}' => '12,500.00',
            '{{rejection_reason}}' => 'Resource planning constraints during this period.',
            '{{alert_count}}' => '4',
            '{{generated_at}}' => now()->format('d M Y H:i'),
            '{{highest_severity}}' => 'CRITICAL',
            '{{crew_operations_url}}' => url('/organization/crew-operations'),
            '{{alerts_table}}' => CrewOperationalAlertDigestPresenter::sampleTable(),
            '{{invitee_name}}' => 'Alex Invitee',
            '{{inviter_name}}' => 'Jordan Admin',
            '{{brand_name}}' => $organizationName,
            '{{accept_url}}' => url('/invitations/accept?token=preview-token'),
            '{{expires_at}}' => now()->addDays(7)->format('M j, Y'),
            '{{role_name}}' => 'Manager',
            '{{recipient_name}}' => 'Jane Smith',
            '{{document_title}}' => 'Employment Contract',
            '{{document_type}}' => 'Contract',
            '{{action_label}}' => 'Sign',
            '{{action_url}}' => url('/document-action/preview-token'),
            '{{step_label}}' => 'Subject employee',
            '{{days_remaining}}' => '3',
            '{{user_name}}' => 'Jane Smith',
            '{{reset_url}}' => url('/reset-password/preview-token'),
            '{{expire_minutes}}' => '60',
            '{{signature_url}}' => url('/signatures/preview-token'),
            '{{requirement_number}}' => 'REQ-1001',
            '{{client_name}}' => 'Acme Shipping',
            '{{project_name}}' => 'Vessel Alpha',
            '{{submitter_name}}' => 'Sam Requester',
            '{{recruiter_name}}' => 'Riley Recruiter',
            '{{approved_at}}' => now()->format('d M Y H:i'),
            '{{return_reason}}' => 'Please clarify salary range and joining date.',
            '{{requirement_url}}' => url('/organization/recruitment/requirements/1'),
            '{{days_label}}' => '3 days remaining',
            '{{status_note}}' => 'approaching its Target Date (3 days remaining)',
            '{{target_date}}' => now()->addDays(3)->format('d M Y'),
            '{{heading}}' => 'Target Date reminder — due in 3 days',
            '{{milestone_label}}' => 'due in 3 days',
            '{{old_deadline}}' => now()->addDays(8)->format('d M Y'),
            '{{new_deadline}}' => now()->addDays(18)->format('d M Y'),
            '{{reason}}' => 'Candidate availability requires additional sourcing time.',
            '{{note}}' => 'Please source from the additional market.',
            '{{headcount_changes}}' => 'Welder: 5 → 8',
            '{{status}}' => 'Approved',
            '{{assignment_no}}' => 'CA-1001',
            '{{phase_label}}' => 'Onboard',
            '{{decision_notes}}' => 'Updated to match passport stamp.',
            '{{correction_url}}' => url('/organization/crew-movement-corrections/1'),
        ];
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderCrewMovementCorrectionDecided(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.crew-movement-correction-decided', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'assignmentNo' => $placeholders['{{assignment_no}}'],
            'employeeName' => $placeholders['{{employee_name}}'],
            'phaseLabel' => $placeholders['{{phase_label}}'],
            'status' => $placeholders['{{status}}'],
            'reason' => 'Incorrect join date recorded.',
            'decisionNotes' => $placeholders['{{decision_notes}}'],
            'correctionUrl' => $placeholders['{{correction_url}}'],
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderRequirementSubmitted(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        string $heading,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.requirement-submitted-for-approval', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'requirementNumber' => $placeholders['{{requirement_number}}'],
            'submitterName' => $placeholders['{{submitter_name}}'],
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'heading' => $heading,
            'details' => $this->sampleRequirementDetails($placeholders),
            'requirementUrl' => $placeholders['{{requirement_url}}'],
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderRequirementApproved(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.requirement-approved', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'requirementNumber' => $placeholders['{{requirement_number}}'],
            'approverName' => $placeholders['{{approver_name}}'],
            'approvedAtFormatted' => $placeholders['{{approved_at}}'],
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'details' => $this->sampleRequirementDetails($placeholders),
            'requirementUrl' => $placeholders['{{requirement_url}}'],
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderRequirementReturned(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.requirement-returned', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'requirementNumber' => $placeholders['{{requirement_number}}'],
            'recruiterName' => $placeholders['{{recruiter_name}}'],
            'returnReason' => $placeholders['{{return_reason}}'],
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'details' => $this->sampleRequirementDetails($placeholders),
            'requirementUrl' => $placeholders['{{requirement_url}}'],
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderRequirementTargetDateReminder(
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.requirement-target-date-reminder', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'requirementNumber' => $placeholders['{{requirement_number}}'],
            'heading' => $placeholders['{{heading}}'],
            'intro' => $introMessage !== '' ? $introMessage : "This recruitment requirement is {$placeholders['{{status_note}}']}.",
            'details' => [
                ...$this->sampleRequirementDetails($placeholders),
                ['label' => 'Target date', 'value' => $placeholders['{{target_date}}']],
                ['label' => 'Status', 'value' => $placeholders['{{days_label}}']],
            ],
            'requirementUrl' => $placeholders['{{requirement_url}}'],
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderRequirementDeadlineExtension(
        string $slug,
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        $heading = match ($slug) {
            'requirement_deadline_extension_requested' => 'Deadline extension requires your approval',
            'requirement_deadline_extension_approved' => 'Deadline extension approved',
            'requirement_deadline_extension_rejected' => 'Deadline extension rejected',
            default => 'Requirement deadline updated by requester',
        };

        $details = [
            ...$this->sampleRequirementDetails($placeholders),
            ['label' => 'Current deadline', 'value' => $placeholders['{{old_deadline}}']],
            ['label' => 'Requested deadline', 'value' => $placeholders['{{new_deadline}}']],
        ];

        if ($slug === 'requirement_deadline_extension_requested') {
            $details[] = ['label' => 'Requested by', 'value' => $placeholders['{{recruiter_name}}']];
            $details[] = ['label' => 'Reason', 'value' => $placeholders['{{reason}}']];
        }

        return View::make('mail.requirement-deadline-extension', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'requirementNumber' => $placeholders['{{requirement_number}}'],
            'heading' => $heading,
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'details' => $details,
            'requirementUrl' => $placeholders['{{requirement_url}}'],
            'ctaLabel' => $slug === 'requirement_deadline_extension_requested' ? 'Review request' : 'View requirement',
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function renderRequirementHeadcountRevision(
        string $slug,
        string $subject,
        string $organizationName,
        string $introMessage,
        array $placeholders,
        bool $includeCompanyFooter,
    ): string {
        $heading = match ($slug) {
            'requirement_headcount_revision_approved' => 'Headcount revision approved',
            'requirement_headcount_revision_rejected' => 'Headcount revision rejected',
            default => 'Headcount revision requires your approval',
        };

        $details = [
            ...$this->sampleRequirementDetails($placeholders),
            ['label' => 'Changes', 'value' => $placeholders['{{headcount_changes}}']],
            ['label' => 'Requested by', 'value' => $placeholders['{{submitter_name}}']],
        ];

        if ($slug === 'requirement_headcount_revision_requested') {
            $details[] = ['label' => 'Reason', 'value' => $placeholders['{{reason}}']];
        }

        if ($slug === 'requirement_headcount_revision_rejected') {
            $details[] = ['label' => 'Official headcount', 'value' => 'Unchanged'];
        }

        return View::make('mail.requirement-deadline-extension', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'requirementNumber' => $placeholders['{{requirement_number}}'],
            'heading' => $heading,
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'details' => $details,
            'requirementUrl' => $placeholders['{{requirement_url}}'].'#headcount-revision-request',
            'ctaLabel' => $slug === 'requirement_headcount_revision_requested' ? 'Review request' : 'View requirement',
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    /**
     * @param  array<string, string>  $placeholders
     * @return list<array{label: string, value: string}>
     */
    private function sampleRequirementDetails(array $placeholders): array
    {
        return [
            ['label' => 'Requirement', 'value' => $placeholders['{{requirement_number}}']],
            ['label' => 'Client', 'value' => $placeholders['{{client_name}}']],
            ['label' => 'Project', 'value' => $placeholders['{{project_name}}']],
            ['label' => 'Positions', 'value' => 'Able Seaman × 2'],
        ];
    }

    private function renderDocumentRecipientActionRequest(
        string $subject,
        string $organizationName,
        string $bodyHtml,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.document-recipient-action-request', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'bodyHtml' => $bodyHtml,
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    private function renderCrewOperationalAlertDigest(
        string $subject,
        string $organizationName,
        string $bodyHtml,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.crew-operational-alert-digest', [
            'subjectLine' => $subject,
            'organizationName' => $organizationName,
            'bodyHtml' => $bodyHtml,
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
    }

    private function renderUserInvitation(
        string $subject,
        string $organizationName,
        string $bodyHtml,
        bool $includeCompanyFooter,
    ): string {
        return View::make('mail.bulk-document', [
            'subjectLine' => $subject,
            'bodyMessage' => $bodyHtml,
            'organizationName' => $organizationName,
            'includeCompanyFooter' => $includeCompanyFooter,
        ])->render();
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
