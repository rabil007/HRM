<?php

namespace App\Jobs;

use App\Enums\Recruitment\RequirementStatus;
use App\Mail\RequirementApprovedMail;
use App\Mail\RequirementReturnedMail;
use App\Mail\RequirementSubmittedForApprovalMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\ComposeRequirementLifecycleMail;
use App\Support\Recruitment\RequirementLifecycleEmailPayload;
use App\Support\Recruitment\RequirementNotificationRecipients;
use App\Support\Recruitment\RequirementPresenter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverRequirementLifecycleEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(): void
    {
        $event = (string) ($this->payload['event'] ?? '');
        $requirementId = (int) ($this->payload['requirement_id'] ?? 0);
        $companyId = (int) ($this->payload['company_id'] ?? 0);

        $requirement = RecruitmentRequirement::query()
            ->whereKey($requirementId)
            ->where('company_id', $companyId)
            ->with([
                'client:id,name',
                'project:id,title',
                'lines.position:id,title',
                'company:id,name',
            ])
            ->first();

        if ($requirement === null) {
            $this->skip('requirement_not_found');

            return;
        }

        $validity = $this->validateEventStillCurrent($requirement);
        if ($validity !== null) {
            $this->skip($validity);

            return;
        }

        match ($event) {
            RequirementLifecycleEmailPayload::EVENT_SUBMITTED,
            RequirementLifecycleEmailPayload::EVENT_REASSIGNED => $this->sendSubmitted($requirement),
            RequirementLifecycleEmailPayload::EVENT_APPROVED => $this->sendApproved($requirement),
            RequirementLifecycleEmailPayload::EVENT_RETURNED => $this->sendReturned($requirement),
            default => $this->skip('unknown_event'),
        };
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Requirement lifecycle email job failed after retries.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'event' => (string) ($this->payload['event'] ?? ''),
            'status_transition_id' => $this->payload['status_transition_id'] ?? null,
            'exception_class' => $exception::class,
            'exception_message' => $this->sanitizeExceptionMessage($exception->getMessage()),
        ]);
    }

    private function validateEventStillCurrent(RecruitmentRequirement $requirement): ?string
    {
        $event = (string) ($this->payload['event'] ?? '');
        $transitionId = isset($this->payload['status_transition_id'])
            ? (int) $this->payload['status_transition_id']
            : null;

        if ($transitionId === null || $transitionId <= 0) {
            return 'missing_status_transition';
        }

        $transition = RecruitmentRequirementStatusTransition::query()
            ->whereKey($transitionId)
            ->where('company_id', (int) $requirement->company_id)
            ->where('recruitment_requirement_id', (int) $requirement->id)
            ->first();

        if ($transition === null) {
            return 'status_transition_not_found';
        }

        return match ($event) {
            RequirementLifecycleEmailPayload::EVENT_SUBMITTED => $this->validateSubmittedStillCurrent($requirement, $transition),
            RequirementLifecycleEmailPayload::EVENT_REASSIGNED => $this->validateReassignedStillCurrent($requirement, $transition),
            RequirementLifecycleEmailPayload::EVENT_APPROVED => $this->validateApprovedTransition($transition),
            RequirementLifecycleEmailPayload::EVENT_RETURNED => $this->validateReturnedTransition($transition),
            default => 'unknown_event',
        };
    }

    private function validateSubmittedStillCurrent(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): ?string {
        if ((string) $transition->to_status !== RequirementStatus::PendingApproval->value) {
            return 'transition_not_submission';
        }

        $expectedStatus = (string) ($this->payload['expected_status'] ?? RequirementStatus::PendingApproval->value);
        if ($requirement->status->value !== $expectedStatus) {
            return 'submission_superseded_by_status';
        }

        $expectedRecruiterId = isset($this->payload['expected_recruiter_id'])
            ? (int) $this->payload['expected_recruiter_id']
            : null;

        if ($expectedRecruiterId === null || (int) $requirement->assigned_to !== $expectedRecruiterId) {
            return 'submission_reassigned_before_delivery';
        }

        // A newer pending-approval transition (e.g. resubmit) supersedes this submission event.
        $newerSubmissionExists = RecruitmentRequirementStatusTransition::query()
            ->where('company_id', (int) $requirement->company_id)
            ->where('recruitment_requirement_id', (int) $requirement->id)
            ->where('to_status', RequirementStatus::PendingApproval->value)
            ->where('id', '>', (int) $transition->id)
            ->exists();

        if ($newerSubmissionExists) {
            return 'submission_superseded_by_newer_transition';
        }

        return null;
    }

    private function validateReassignedStillCurrent(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): ?string {
        $expectedRecruiterId = isset($this->payload['expected_recruiter_id'])
            ? (int) $this->payload['expected_recruiter_id']
            : null;
        $assignmentVersion = isset($this->payload['assignment_version'])
            ? (string) $this->payload['assignment_version']
            : null;

        if ($requirement->status !== RequirementStatus::PendingApproval) {
            return 'reassignment_no_longer_pending';
        }

        if ($expectedRecruiterId === null || (int) $requirement->assigned_to !== $expectedRecruiterId) {
            return 'reassignment_superseded_by_newer_assignee';
        }

        if ($assignmentVersion === null || $assignmentVersion !== (string) $transition->id) {
            return 'reassignment_version_mismatch';
        }

        // A newer same-status reassignment transition means this event is obsolete.
        $newerReassignmentExists = RecruitmentRequirementStatusTransition::query()
            ->where('company_id', (int) $requirement->company_id)
            ->where('recruitment_requirement_id', (int) $requirement->id)
            ->where('from_status', RequirementStatus::PendingApproval->value)
            ->where('to_status', RequirementStatus::PendingApproval->value)
            ->where('id', '>', (int) $transition->id)
            ->exists();

        if ($newerReassignmentExists) {
            return 'reassignment_superseded_by_newer_transition';
        }

        return null;
    }

    private function validateApprovedTransition(RecruitmentRequirementStatusTransition $transition): ?string
    {
        if ((string) $transition->to_status !== RequirementStatus::Open->value) {
            return 'transition_not_approval';
        }

        return null;
    }

    private function validateReturnedTransition(RecruitmentRequirementStatusTransition $transition): ?string
    {
        if ((string) $transition->to_status !== RequirementStatus::Returned->value) {
            return 'transition_not_return';
        }

        return null;
    }

    private function sendSubmitted(RecruitmentRequirement $requirement): void
    {
        $primaryUserId = isset($this->payload['primary_recipient_user_id'])
            ? (int) $this->payload['primary_recipient_user_id']
            : null;

        $primary = $primaryUserId !== null
            ? User::query()->find($primaryUserId)
            : null;

        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $this->usersForIds($this->submissionCcUserIds()),
            primaryMustBeEligibleApprover: true,
        );

        if ($resolved['to_user_id'] === null) {
            $this->skip('primary_recruiter_ineligible');

            return;
        }

        // Guard against resolving to a different current recruiter than the snapshotted event.
        if ($primaryUserId !== null && (int) $resolved['to_user_id'] !== $primaryUserId) {
            $this->skip('resolved_primary_mismatch');

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            $this->skip('primary_missing_usable_email');

            return;
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);
        $submitterName = $this->displayNameForUserId(
            isset($this->payload['submitter_user_id']) ? (int) $this->payload['submitter_user_id'] : null,
            'Requester',
        );

        $event = (string) ($this->payload['event'] ?? '');
        $compose = app(ComposeRequirementLifecycleMail::class);
        $slug = $compose->slugForLifecycleEvent($event);
        if ($slug === null) {
            $this->skip('unknown_event');

            return;
        }

        $template = $compose->findEnabled($slug);
        if ($template === null) {
            $this->skip('template_disabled_or_missing');

            return;
        }

        $requirementUrl = $this->requirementUrl($requirement);
        $placeholders = $compose->lifecyclePlaceholders(
            requirement: $requirement,
            requirementUrl: $requirementUrl,
            submitterName: $submitterName,
        );
        $subject = $compose->render($template->subject, $placeholders);
        $introMessage = trim($compose->render($template->body_html, $placeholders));

        $mailable = new RequirementSubmittedForApprovalMail(
            subjectLine: $subject,
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            submitterName: $submitterName,
            details: $this->commonDetails($requirement),
            requirementUrl: $requirementUrl,
            introMessage: $introMessage !== '' ? $introMessage : null,
            heading: $event === RequirementLifecycleEmailPayload::EVENT_REASSIGNED
                ? 'Requirement assigned for approval'
                : 'Requirement pending approval',
            includeCompanyFooter: (bool) $template->include_company_footer,
        );

        $this->sendMail($toEmail, $ccEmails, $mailable, $requirement);
    }

    private function sendApproved(RecruitmentRequirement $requirement): void
    {
        $primaryUserId = isset($this->payload['primary_recipient_user_id'])
            ? (int) $this->payload['primary_recipient_user_id']
            : null;

        $primary = $primaryUserId !== null
            ? User::query()->find($primaryUserId)
            : null;

        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $this->usersForIds($this->decisionCcUserIds()),
            primaryMustBeEligibleApprover: false,
        );

        if ($resolved['to_user_id'] === null) {
            $this->skip('requester_ineligible');

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            $this->skip('requester_missing_usable_email');

            return;
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);
        $approverName = $this->displayNameForUserId(
            isset($this->payload['actor_user_id']) ? (int) $this->payload['actor_user_id'] : null,
            'Recruiter',
        );
        $approvedAtFormatted = $this->formatEventOccurredAt();

        $compose = app(ComposeRequirementLifecycleMail::class);
        $template = $compose->findEnabled(ComposeRequirementLifecycleMail::SLUG_APPROVED);
        if ($template === null) {
            $this->skip('template_disabled_or_missing');

            return;
        }

        $requirementUrl = $this->requirementUrl($requirement);
        $placeholders = $compose->lifecyclePlaceholders(
            requirement: $requirement,
            requirementUrl: $requirementUrl,
            approverName: $approverName,
            approvedAtFormatted: $approvedAtFormatted,
        );

        $mailable = new RequirementApprovedMail(
            subjectLine: $compose->render($template->subject, $placeholders),
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            approverName: $approverName,
            approvedAtFormatted: $approvedAtFormatted,
            details: $this->commonDetails($requirement),
            requirementUrl: $requirementUrl,
            introMessage: trim($compose->render($template->body_html, $placeholders)) ?: null,
            includeCompanyFooter: (bool) $template->include_company_footer,
        );

        $this->sendMail($toEmail, $ccEmails, $mailable, $requirement);
    }

    private function sendReturned(RecruitmentRequirement $requirement): void
    {
        $primaryUserId = isset($this->payload['primary_recipient_user_id'])
            ? (int) $this->payload['primary_recipient_user_id']
            : null;

        $primary = $primaryUserId !== null
            ? User::query()->find($primaryUserId)
            : null;

        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $this->usersForIds($this->decisionCcUserIds()),
            primaryMustBeEligibleApprover: false,
        );

        if ($resolved['to_user_id'] === null) {
            $this->skip('requester_ineligible');

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            $this->skip('requester_missing_usable_email');

            return;
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);
        $recruiterName = $this->displayNameForUserId(
            isset($this->payload['actor_user_id']) ? (int) $this->payload['actor_user_id'] : null,
            'Recruiter',
        );
        $returnReason = (string) ($this->payload['return_reason'] ?? '');

        $compose = app(ComposeRequirementLifecycleMail::class);
        $template = $compose->findEnabled(ComposeRequirementLifecycleMail::SLUG_RETURNED);
        if ($template === null) {
            $this->skip('template_disabled_or_missing');

            return;
        }

        $requirementUrl = $this->requirementUrl($requirement);
        $placeholders = $compose->lifecyclePlaceholders(
            requirement: $requirement,
            requirementUrl: $requirementUrl,
            recruiterName: $recruiterName,
            returnReason: $returnReason,
        );

        $mailable = new RequirementReturnedMail(
            subjectLine: $compose->render($template->subject, $placeholders),
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            recruiterName: $recruiterName,
            returnReason: $returnReason,
            details: $this->commonDetails($requirement),
            requirementUrl: $requirementUrl,
            introMessage: trim($compose->render($template->body_html, $placeholders)) ?: null,
            includeCompanyFooter: (bool) $template->include_company_footer,
        );

        $this->sendMail($toEmail, $ccEmails, $mailable, $requirement);
    }

    /**
     * @return list<int>
     */
    private function submissionCcUserIds(): array
    {
        $ids = [];

        $requesterId = isset($this->payload['requester_user_id']) ? (int) $this->payload['requester_user_id'] : null;
        $submitterId = isset($this->payload['submitter_user_id']) ? (int) $this->payload['submitter_user_id'] : null;
        $primaryId = isset($this->payload['primary_recipient_user_id']) ? (int) $this->payload['primary_recipient_user_id'] : null;

        if ($requesterId !== null && $requesterId !== $primaryId) {
            $ids[] = $requesterId;
        }

        if ($submitterId !== null && $submitterId !== $primaryId && $submitterId !== $requesterId) {
            $ids[] = $submitterId;
        }

        foreach ($this->additionalCcUserIds() as $ccId) {
            if ($ccId !== $primaryId) {
                $ids[] = $ccId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function decisionCcUserIds(): array
    {
        $ids = [];

        $requesterId = isset($this->payload['requester_user_id']) ? (int) $this->payload['requester_user_id'] : null;
        $submitterId = isset($this->payload['submitter_user_id']) ? (int) $this->payload['submitter_user_id'] : null;
        $primaryId = isset($this->payload['primary_recipient_user_id']) ? (int) $this->payload['primary_recipient_user_id'] : null;

        if ($submitterId !== null && $submitterId !== $primaryId && $submitterId !== $requesterId) {
            $ids[] = $submitterId;
        }

        foreach ($this->additionalCcUserIds() as $ccId) {
            if ($ccId !== $primaryId) {
                $ids[] = $ccId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function additionalCcUserIds(): array
    {
        $raw = $this->payload['additional_cc_user_ids'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return list<User>
     */
    private function usersForIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'status', 'deleted_at', 'company_id'])
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return list<string>
     */
    private function emailsForUserIds(array $userIds, string $excludeEmail): array
    {
        if ($userIds === []) {
            return [];
        }

        $emails = [];
        $excludeKey = strtolower($excludeEmail);

        $users = User::query()->whereIn('id', $userIds)->get(['id', 'email', 'status', 'deleted_at']);
        foreach ($users as $user) {
            $email = RequirementNotificationRecipients::usableEmail($user);
            if ($email === null) {
                continue;
            }
            if (strtolower($email) === $excludeKey) {
                continue;
            }
            $emails[] = $email;
        }

        return RequirementNotificationRecipients::dedupeEmails($emails);
    }

    /**
     * @param  list<string>  $cc
     */
    private function sendMail(
        string $to,
        array $cc,
        RequirementSubmittedForApprovalMail|RequirementApprovedMail|RequirementReturnedMail $mailable,
        RecruitmentRequirement $requirement,
    ): void {
        $pending = Mail::to($to);
        if ($cc !== []) {
            $pending->cc($cc);
        }

        // Send synchronously inside the lifecycle job so addresses are not serialized ahead of time.
        // Unexpected transport/render failures must escape handle() for Laravel retries.
        $pending->send($mailable);

        Log::info('Sent recruitment requirement lifecycle email.', [
            'event' => (string) ($this->payload['event'] ?? ''),
            'requirement_id' => $requirement->id,
            'company_id' => $requirement->company_id,
            'requirement_number' => $requirement->requirement_number,
            'status_transition_id' => $this->payload['status_transition_id'] ?? null,
            'cc_count' => count($cc),
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function commonDetails(RecruitmentRequirement $requirement): array
    {
        $positions = $requirement->lines
            ->map(function ($line): string {
                $title = (string) ($line->position?->title ?? 'Position');
                $count = (int) $line->required_headcount;
                $salary = RequirementPresenter::formatSalaryRange(
                    $line->salary_min,
                    $line->salary_max,
                    $line->salary_currency_code,
                );

                if ($salary !== 'Not specified') {
                    return "{$title} × {$count} ({$salary})";
                }

                return "{$title} × {$count}";
            })
            ->filter()
            ->values()
            ->all();

        $details = [
            ['label' => 'Requirement', 'value' => (string) $requirement->requirement_number],
            ['label' => 'Client', 'value' => (string) ($requirement->client?->name ?? '—')],
        ];

        if ($requirement->project !== null) {
            $details[] = ['label' => 'Project', 'value' => (string) $requirement->project->title];
        }

        $details[] = [
            'label' => 'Positions',
            'value' => $positions === [] ? '—' : implode(', ', $positions),
        ];
        $details[] = [
            'label' => 'Target Date',
            'value' => $requirement->required_by_date?->format('d-m-Y') ?? '—',
        ];
        $details[] = [
            'label' => 'Priority',
            'value' => $requirement->priority->label(),
        ];
        $details[] = [
            'label' => 'Requester',
            'value' => $this->displayNameForUserId(
                isset($this->payload['requester_user_id']) ? (int) $this->payload['requester_user_id'] : null,
                '—',
            ),
        ];

        return $details;
    }

    private function displayNameForUserId(?int $userId, string $fallback): string
    {
        if ($userId === null) {
            return $fallback;
        }

        $name = User::query()->whereKey($userId)->value('name');

        return filled($name) ? (string) $name : $fallback;
    }

    private function formatEventOccurredAt(): string
    {
        $raw = $this->payload['event_occurred_at'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return now()->format('d-m-Y H:i');
        }

        try {
            return Carbon::parse($raw)->format('d-m-Y H:i');
        } catch (Throwable) {
            return now()->format('d-m-Y H:i');
        }
    }

    private function organizationName(RecruitmentRequirement $requirement): string
    {
        if ($requirement->company instanceof Company && filled($requirement->company->name)) {
            return (string) $requirement->company->name;
        }

        return 'OMS-HRM';
    }

    private function requirementUrl(RecruitmentRequirement $requirement): string
    {
        return route('organization.recruitment.requirements.show', $requirement);
    }

    private function skip(string $reason): void
    {
        Log::warning('Requirement lifecycle email skipped.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'event' => (string) ($this->payload['event'] ?? ''),
            'status_transition_id' => $this->payload['status_transition_id'] ?? null,
            'reason' => $reason,
        ]);
    }

    private function sanitizeExceptionMessage(string $message): string
    {
        $sanitized = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;

        return mb_substr($sanitized, 0, 500);
    }
}
