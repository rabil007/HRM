<?php

namespace App\Jobs;

use App\Mail\RequirementHeadcountRevisionMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\User;
use App\Support\Recruitment\ComposeRequirementLifecycleMail;
use App\Support\Recruitment\RequirementHeadcountRevisionEmailPayload;
use App\Support\Recruitment\RequirementNotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverRequirementHeadcountRevisionEmailJob implements ShouldQueue
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
        $revisionId = (int) ($this->payload['revision_id'] ?? 0);

        $requirement = RecruitmentRequirement::query()
            ->whereKey($requirementId)
            ->where('company_id', $companyId)
            ->with(['client:id,name', 'project:id,title', 'company:id,name'])
            ->first();

        if ($requirement === null) {
            $this->skip('requirement_not_found');

            return;
        }

        $revision = RecruitmentRequirementHeadcountRevision::query()
            ->whereKey($revisionId)
            ->where('company_id', $companyId)
            ->where('recruitment_requirement_id', $requirementId)
            ->first();

        if ($revision === null) {
            $this->skip('revision_not_found');

            return;
        }

        $expectedStatus = (string) ($this->payload['expected_status'] ?? '');
        if ($expectedStatus !== '' && $revision->status->value !== $expectedStatus) {
            $this->skip('revision_status_changed');

            return;
        }

        $primaryUserId = isset($this->payload['primary_recipient_user_id'])
            ? (int) $this->payload['primary_recipient_user_id']
            : null;
        $primary = $primaryUserId !== null ? User::query()->find($primaryUserId) : null;
        $ccIds = $this->payload['additional_cc_user_ids'] ?? [];
        $ccUsers = is_array($ccIds)
            ? User::query()->whereIn('id', array_map('intval', $ccIds))->get()->all()
            : [];

        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $ccUsers,
            primaryMustBeEligibleApprover: false,
        );

        if ($resolved['to_user_id'] === null) {
            $this->skip('primary_ineligible');

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            $this->skip('primary_missing_usable_email');

            return;
        }

        $compose = app(ComposeRequirementLifecycleMail::class);
        $slug = $compose->slugForHeadcountRevisionEvent($event);
        if ($slug === null) {
            $this->skip('unknown_event');

            return;
        }

        $template = $compose->findEnabled($slug);
        if ($template === null) {
            $this->skip('template_disabled_or_missing');

            return;
        }

        $actorName = $this->displayNameForUserId(
            isset($this->payload['actor_user_id']) ? (int) $this->payload['actor_user_id'] : null,
            'User',
        );
        $requestedByName = $this->displayNameForUserId(
            isset($this->payload['requested_by_user_id']) ? (int) $this->payload['requested_by_user_id'] : null,
            'Requester',
        );
        $changes = filled($this->payload['headcount_changes'] ?? null)
            ? (string) $this->payload['headcount_changes']
            : '—';
        $reason = filled($this->payload['reason'] ?? null) ? (string) $this->payload['reason'] : null;
        $note = filled($this->payload['note'] ?? null) ? (string) $this->payload['note'] : null;
        $initiator = (string) ($this->payload['initiator'] ?? '');
        $requirementUrl = route('organization.recruitment.requirements.show', $requirement, absolute: true).'#headcount-revision-request';

        $placeholders = $compose->headcountRevisionPlaceholders(
            requirement: $requirement,
            requirementUrl: $requirementUrl,
            actorName: $actorName,
            requesterName: $requestedByName,
            headcountChanges: $changes,
            reason: $initiator === 'recruiter' ? ($reason ?? '') : '',
            note: $initiator === 'requester' ? ($reason ?? '') : ($note ?? ''),
        );

        $heading = match ($event) {
            RequirementHeadcountRevisionEmailPayload::EVENT_REQUESTED => 'Headcount revision requires your approval',
            RequirementHeadcountRevisionEmailPayload::EVENT_APPROVED => 'Headcount revision approved',
            default => 'Headcount revision rejected',
        };

        $details = [
            ['label' => 'Requirement', 'value' => (string) $requirement->requirement_number],
            ['label' => 'Client', 'value' => (string) ($requirement->client?->name ?? '—')],
            ['label' => 'Changes', 'value' => $changes],
            ['label' => 'Requested by', 'value' => $requestedByName],
        ];

        if ($event === RequirementHeadcountRevisionEmailPayload::EVENT_REQUESTED && $reason !== null) {
            $details[] = [
                'label' => $initiator === 'requester' ? 'Note' : 'Reason',
                'value' => $reason,
            ];
        }

        if ($event === RequirementHeadcountRevisionEmailPayload::EVENT_REJECTED) {
            $details[] = ['label' => 'Official headcount', 'value' => 'Unchanged'];
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);
        $mailable = new RequirementHeadcountRevisionMail(
            subjectLine: $compose->render($template->subject, $placeholders),
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            heading: $heading,
            details: $details,
            requirementUrl: $requirementUrl,
            ctaLabel: $event === RequirementHeadcountRevisionEmailPayload::EVENT_REQUESTED
                ? 'Review request'
                : 'View requirement',
            introMessage: trim($compose->render($template->body_html, $placeholders)) ?: null,
            includeCompanyFooter: (bool) $template->include_company_footer,
        );

        $pending = Mail::to($toEmail);
        if ($ccEmails !== []) {
            $pending->cc($ccEmails);
        }
        $pending->send($mailable);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Requirement headcount revision email job failed after retries.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'event' => (string) ($this->payload['event'] ?? ''),
            'revision_id' => $this->payload['revision_id'] ?? null,
            'exception_class' => $exception::class,
            'exception_message' => mb_substr($exception->getMessage(), 0, 500),
        ]);
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
            if ($email === null || strtolower($email) === $excludeKey) {
                continue;
            }
            $emails[] = $email;
        }

        return RequirementNotificationRecipients::dedupeEmails($emails);
    }

    private function displayNameForUserId(?int $userId, string $fallback): string
    {
        if ($userId === null) {
            return $fallback;
        }

        $name = User::query()->whereKey($userId)->value('name');

        return filled($name) ? (string) $name : $fallback;
    }

    private function organizationName(RecruitmentRequirement $requirement): string
    {
        if ($requirement->company instanceof Company && filled($requirement->company->name)) {
            return (string) $requirement->company->name;
        }

        return 'OMS-HRM';
    }

    private function skip(string $reason): void
    {
        Log::warning('Requirement headcount revision email skipped.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'event' => (string) ($this->payload['event'] ?? ''),
            'revision_id' => $this->payload['revision_id'] ?? null,
            'reason' => $reason,
        ]);
    }
}
