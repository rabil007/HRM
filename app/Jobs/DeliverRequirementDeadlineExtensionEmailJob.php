<?php

namespace App\Jobs;

use App\Mail\RequirementDeadlineExtensionMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\User;
use App\Support\Recruitment\ComposeRequirementLifecycleMail;
use App\Support\Recruitment\RequirementDeadlineExtensionEmailPayload;
use App\Support\Recruitment\RequirementNotificationRecipients;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverRequirementDeadlineExtensionEmailJob implements ShouldQueue
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
        $extensionId = (int) ($this->payload['extension_id'] ?? 0);

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

        $extension = RecruitmentRequirementDeadlineExtension::query()
            ->whereKey($extensionId)
            ->where('company_id', $companyId)
            ->where('recruitment_requirement_id', $requirementId)
            ->first();

        if ($extension === null) {
            $this->skip('extension_not_found');

            return;
        }

        $expectedStatus = (string) ($this->payload['expected_status'] ?? '');
        if ($expectedStatus !== '' && $extension->status->value !== $expectedStatus) {
            $this->skip('extension_status_changed');

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
        $slug = $compose->slugForDeadlineExtensionEvent($event);
        if ($slug === null) {
            $this->skip('unknown_event');

            return;
        }

        $template = $compose->findEnabled($slug);
        if ($template === null) {
            $this->skip('template_disabled_or_missing');

            return;
        }

        $oldDeadline = $this->formatDate((string) ($this->payload['old_deadline'] ?? ''));
        $requestedDeadline = $this->formatDate((string) ($this->payload['requested_deadline'] ?? ''));
        $actorName = $this->displayNameForUserId(
            isset($this->payload['actor_user_id']) ? (int) $this->payload['actor_user_id'] : null,
            'User',
        );
        $requestedByName = $this->displayNameForUserId(
            isset($this->payload['requested_by_user_id']) ? (int) $this->payload['requested_by_user_id'] : null,
            'Recruiter',
        );
        $reason = filled($this->payload['reason'] ?? null) ? (string) $this->payload['reason'] : null;
        $note = filled($this->payload['note'] ?? null) ? (string) $this->payload['note'] : null;
        $requirementUrl = route('organization.recruitment.requirements.show', $requirement, absolute: true);

        $placeholders = $compose->deadlineExtensionPlaceholders(
            requirement: $requirement,
            requirementUrl: $requirementUrl,
            actorName: $actorName,
            recruiterName: $requestedByName,
            oldDeadline: $oldDeadline,
            newDeadline: $requestedDeadline,
            reason: $reason ?? '',
            note: $event === RequirementDeadlineExtensionEmailPayload::EVENT_DIRECT
                ? ($reason ?? '')
                : ($note ?? ''),
        );

        $heading = match ($event) {
            RequirementDeadlineExtensionEmailPayload::EVENT_REQUESTED => 'Deadline extension requires your approval',
            RequirementDeadlineExtensionEmailPayload::EVENT_APPROVED => 'Deadline extension approved',
            RequirementDeadlineExtensionEmailPayload::EVENT_REJECTED => 'Deadline extension rejected',
            default => 'Requirement deadline updated by requester',
        };

        $ctaLabel = $event === RequirementDeadlineExtensionEmailPayload::EVENT_REQUESTED
            ? 'Review request'
            : 'View requirement';

        $details = [
            ['label' => 'Requirement', 'value' => (string) $requirement->requirement_number],
            ['label' => 'Client', 'value' => (string) ($requirement->client?->name ?? '—')],
            ['label' => 'Current deadline', 'value' => $event === RequirementDeadlineExtensionEmailPayload::EVENT_APPROVED ? $requestedDeadline : $oldDeadline],
            ['label' => 'Requested deadline', 'value' => $requestedDeadline],
        ];

        if ($event === RequirementDeadlineExtensionEmailPayload::EVENT_REQUESTED) {
            $details[] = ['label' => 'Requested by', 'value' => $requestedByName];
            if ($reason !== null) {
                $details[] = ['label' => 'Reason', 'value' => $reason];
            }
        }

        if ($event === RequirementDeadlineExtensionEmailPayload::EVENT_DIRECT && $reason !== null) {
            $details[] = ['label' => 'Note', 'value' => $reason];
        }

        if ($event === RequirementDeadlineExtensionEmailPayload::EVENT_REJECTED) {
            $details[] = ['label' => 'Official deadline', 'value' => $oldDeadline];
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);

        $mailable = new RequirementDeadlineExtensionMail(
            subjectLine: $compose->render($template->subject, $placeholders),
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            heading: $heading,
            details: $details,
            requirementUrl: $requirementUrl,
            ctaLabel: $ctaLabel,
            introMessage: trim($compose->render($template->body_html, $placeholders)) ?: null,
            includeCompanyFooter: (bool) $template->include_company_footer,
        );

        $pending = Mail::to($toEmail);
        if ($ccEmails !== []) {
            $pending->cc($ccEmails);
        }
        $pending->send($mailable);

        Log::info('Sent recruitment deadline extension email.', [
            'event' => $event,
            'requirement_id' => $requirement->id,
            'company_id' => $requirement->company_id,
            'extension_id' => $extension->id,
            'cc_count' => count($ccEmails),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Requirement deadline extension email job failed after retries.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'event' => (string) ($this->payload['event'] ?? ''),
            'extension_id' => $this->payload['extension_id'] ?? null,
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

    private function formatDate(string $date): string
    {
        if ($date === '') {
            return '—';
        }

        try {
            return Carbon::parse($date)->format('d M Y');
        } catch (Throwable) {
            return $date;
        }
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
        Log::warning('Requirement deadline extension email skipped.', [
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'event' => (string) ($this->payload['event'] ?? ''),
            'extension_id' => $this->payload['extension_id'] ?? null,
            'reason' => $reason,
        ]);
    }
}
