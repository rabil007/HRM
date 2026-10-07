<?php

namespace App\Jobs;

use App\Enums\Recruitment\RequirementStatus;
use App\Enums\Recruitment\RequirementTargetDateReminderMilestone;
use App\Enums\Recruitment\RequirementTargetDateReminderStatus;
use App\Mail\RequirementTargetDateReminderMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementTargetDateReminder;
use App\Models\User;
use App\Support\Recruitment\ComposeRequirementLifecycleMail;
use App\Support\Recruitment\RequirementNotificationRecipients;
use App\Support\Recruitment\RequirementPresenter;
use App\Support\Recruitment\RequirementTargetDateReminderDeliveryKey;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverRequirementTargetDateReminderJob implements ShouldQueue
{
    use Queueable;

    /** Matches typical queue worker `--timeout=600`. */
    public const QUEUE_WORKER_TIMEOUT_SECONDS = 600;

    /** Overlap lock must outlive worker timeout so two jobs cannot run concurrently. */
    public const OVERLAP_LOCK_EXPIRE_SECONDS = self::QUEUE_WORKER_TIMEOUT_SECONDS + 60;

    /** Delay before an overlapping job retries (avoids burning `$tries` while active job runs). */
    public const OVERLAP_RELEASE_AFTER_SECONDS = 30;

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

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $reminderId = (int) ($this->payload['reminder_id'] ?? 0);

        if ($reminderId <= 0) {
            return [];
        }

        return [
            (new WithoutOverlapping(self::overlapKey($reminderId)))
                ->releaseAfter(self::OVERLAP_RELEASE_AFTER_SECONDS)
                ->expireAfter(self::OVERLAP_LOCK_EXPIRE_SECONDS),
        ];
    }

    public static function overlapKey(int $reminderId): string
    {
        return 'requirement-target-date-reminder:'.$reminderId;
    }

    public function handle(): void
    {
        $reminderId = (int) ($this->payload['reminder_id'] ?? 0);
        $companyId = (int) ($this->payload['company_id'] ?? 0);
        $requirementId = (int) ($this->payload['requirement_id'] ?? 0);
        $targetDate = (string) ($this->payload['target_date'] ?? '');
        $milestoneValue = (string) ($this->payload['milestone'] ?? '');
        $deliveryKey = (string) ($this->payload['delivery_key'] ?? '');
        $claimToken = (string) ($this->payload['claim_token'] ?? '');

        $milestone = RequirementTargetDateReminderMilestone::tryFrom($milestoneValue);
        if ($milestone === null || $reminderId <= 0 || $companyId <= 0 || $requirementId <= 0 || $targetDate === '' || $deliveryKey === '') {
            $this->skip('invalid_payload');

            return;
        }

        if ($claimToken === '') {
            $this->skip('missing_claim_token');

            return;
        }

        $reminder = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('company_id', $companyId)
            ->where('recruitment_requirement_id', $requirementId)
            ->whereDate('target_date', $targetDate)
            ->where('milestone', $milestone->value)
            ->where('delivery_key', $deliveryKey)
            ->first();

        if ($reminder === null) {
            $this->skip('reminder_not_found');

            return;
        }

        if ($reminder->status === RequirementTargetDateReminderStatus::Sent) {
            $this->skip('already_sent');

            return;
        }

        if ($reminder->status === RequirementTargetDateReminderStatus::Skipped) {
            $this->skip('already_skipped');

            return;
        }

        if ($reminder->claim_token !== $claimToken) {
            $this->skip('stale_claim_token');

            return;
        }

        if (! $this->claimForProcessing($reminderId, $claimToken)) {
            $this->skip('not_claimable');

            return;
        }

        $requirement = RecruitmentRequirement::query()
            ->whereKey($requirementId)
            ->where('company_id', $companyId)
            ->with([
                'client:id,name',
                'project:id,title',
                'lines.position:id,title',
                'company:id,name,status,timezone',
                'assignedRecruiter:id,name,email,status,deleted_at',
                'creator:id,name,email,status,deleted_at',
                'submitter:id,name,email,status,deleted_at',
            ])
            ->first();

        if ($requirement === null) {
            $this->markSkipped($reminderId, $claimToken, 'requirement_not_found');

            return;
        }

        if (! $requirement->company instanceof Company || $requirement->company->status !== 'active') {
            $this->markSkipped($reminderId, $claimToken, 'company_inactive');

            return;
        }

        if ($requirement->required_by_date === null) {
            $this->markSkipped($reminderId, $claimToken, 'missing_target_date');

            return;
        }

        $currentTargetDate = $requirement->required_by_date->format('Y-m-d');
        if ($currentTargetDate !== $targetDate) {
            $this->markSkipped($reminderId, $claimToken, 'stale_target_date');

            return;
        }

        if (! in_array($requirement->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
            $this->markSkipped($reminderId, $claimToken, 'status_not_eligible');

            return;
        }

        $expectedDeliveryKey = RequirementTargetDateReminderDeliveryKey::forUser(
            (int) ($this->payload['primary_recipient_user_id'] ?? 0),
        );
        if ($expectedDeliveryKey !== $deliveryKey) {
            $this->markSkipped($reminderId, $claimToken, 'delivery_key_mismatch');

            return;
        }

        $recipients = $this->resolveRecipients($requirement);
        if ($recipients['to_user_id'] === null) {
            $this->markSkipped($reminderId, $claimToken, 'primary_missing_usable_email');

            return;
        }

        $liveDeliveryKey = RequirementTargetDateReminderDeliveryKey::forUser($recipients['to_user_id']);
        if ($liveDeliveryKey !== $deliveryKey) {
            $this->markSkipped($reminderId, $claimToken, 'primary_recipient_changed');

            return;
        }

        $toUser = User::query()->find($recipients['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            $this->markSkipped($reminderId, $claimToken, 'primary_missing_usable_email');

            return;
        }

        $ccEmails = $this->emailsForUserIds($recipients['cc_user_ids'], $toEmail);
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $todayLocal = CarbonImmutable::now($timezone)->startOfDay();
        $targetLocal = CarbonImmutable::parse($currentTargetDate, $timezone)->startOfDay();
        $daysRemaining = (int) $todayLocal->diffInDays($targetLocal, false);

        $daysLabel = match (true) {
            $daysRemaining === 0 => 'Due today',
            $daysRemaining === 1 => '1 day remaining',
            $daysRemaining > 1 => "{$daysRemaining} days remaining",
            default => 'Overdue',
        };

        $organizationName = filled($requirement->company->name)
            ? (string) $requirement->company->name
            : 'OMS-HRM';

        $compose = app(ComposeRequirementLifecycleMail::class);
        $templateSlug = $compose->slugForTargetDateMilestone($milestone);
        $template = $compose->findBySlug($templateSlug);

        if ($template === null) {
            $this->markRecoverableFailure($reminderId, $claimToken, 'template_missing');

            return;
        }

        if (! $template->enabled) {
            $this->markSkipped($reminderId, $claimToken, 'template_disabled');

            return;
        }

        $requirementUrl = route('organization.recruitment.requirements.show', $requirement);
        $statusNote = $requirement->status === RequirementStatus::OnHold
            ? "currently On Hold. Its Target Date is {$daysLabel}"
            : "approaching its Target Date ({$daysLabel})";
        $milestoneLabel = $milestone === RequirementTargetDateReminderMilestone::ThreeDaysBefore
            ? 'due in 3 days'
            : 'due today';
        $placeholders = $compose->targetDatePlaceholders(
            requirement: $requirement,
            requirementUrl: $requirementUrl,
            daysLabel: $daysLabel,
            statusNote: $statusNote,
            targetDateFormatted: $targetLocal->format('d M Y'),
            heading: $milestone->heading(),
            milestoneLabel: $milestoneLabel,
        );
        $intro = trim($compose->render($template->body_html, $placeholders));
        if ($intro === '') {
            $intro = "This recruitment requirement is {$statusNote}.";
        }

        $mailable = new RequirementTargetDateReminderMail(
            subjectLine: $compose->render($template->subject, $placeholders),
            organizationName: $organizationName,
            requirementNumber: (string) $requirement->requirement_number,
            heading: $milestone->heading(),
            intro: $intro,
            details: $this->details($requirement, $daysLabel),
            requirementUrl: $requirementUrl,
            includeCompanyFooter: (bool) $template->include_company_footer,
        );

        if (! $this->stillOwnsProcessingAttempt($reminderId, $claimToken)) {
            $this->skip('stale_before_send');

            return;
        }

        try {
            $pending = Mail::to($toEmail);
            if ($ccEmails !== []) {
                $pending->cc($ccEmails);
            }
            $pending->send($mailable);
        } catch (Throwable $exception) {
            $this->markSendFailure($reminderId, $claimToken);

            throw $exception;
        }

        if (! $this->markSent($reminderId, $claimToken, $recipients)) {
            return;
        }

        Log::info('Sent recruitment requirement Target Date reminder.', [
            'reminder_id' => $reminderId,
            'requirement_id' => $requirementId,
            'company_id' => $companyId,
            'milestone' => $milestone->value,
            'target_date' => $targetDate,
            'cc_count' => count($ccEmails),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $reminderId = (int) ($this->payload['reminder_id'] ?? 0);
        $claimToken = (string) ($this->payload['claim_token'] ?? '');

        if ($reminderId <= 0 || $claimToken === '') {
            Log::error('Requirement Target Date reminder job failed after retries (missing claim context).', [
                'reminder_id' => $reminderId,
                'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
                'company_id' => (int) ($this->payload['company_id'] ?? 0),
                'milestone' => (string) ($this->payload['milestone'] ?? ''),
                'exception_class' => $exception::class,
                'exception_message' => $this->sanitizeExceptionMessage($exception->getMessage()),
            ]);

            return;
        }

        $updated = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->whereIn('status', [
                RequirementTargetDateReminderStatus::Processing->value,
                RequirementTargetDateReminderStatus::Queued->value,
            ])
            ->update([
                'status' => RequirementTargetDateReminderStatus::Failed->value,
                'skip_reason' => 'job_failed',
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            Log::warning('Requirement Target Date reminder failed() ignored stale claim.', [
                'reminder_id' => $reminderId,
                'claim_token' => $claimToken,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        Log::error('Requirement Target Date reminder job failed after retries.', [
            'reminder_id' => $reminderId,
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'milestone' => (string) ($this->payload['milestone'] ?? ''),
            'exception_class' => $exception::class,
            'exception_message' => $this->sanitizeExceptionMessage($exception->getMessage()),
        ]);
    }

    private function claimForProcessing(int $reminderId, string $claimToken): bool
    {
        $claimed = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->whereIn('status', [
                RequirementTargetDateReminderStatus::Queued->value,
                RequirementTargetDateReminderStatus::Failed->value,
            ])
            ->update([
                'status' => RequirementTargetDateReminderStatus::Processing->value,
                'claimed_at' => now(),
                'skip_reason' => null,
                'updated_at' => now(),
            ]);

        return $claimed > 0;
    }

    private function stillOwnsProcessingAttempt(int $reminderId, string $claimToken): bool
    {
        return RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->where('status', RequirementTargetDateReminderStatus::Processing->value)
            ->exists();
    }

    /**
     * @param  array{to_user_id: int|null, cc_user_ids: list<int>}  $recipients
     */
    private function markSent(int $reminderId, string $claimToken, array $recipients): bool
    {
        $updated = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->where('status', RequirementTargetDateReminderStatus::Processing->value)
            ->update([
                'status' => RequirementTargetDateReminderStatus::Sent->value,
                'sent_at' => now(),
                'primary_recipient_user_id' => $recipients['to_user_id'],
                'cc_user_ids' => $recipients['cc_user_ids'],
                'skip_reason' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            Log::warning('Requirement Target Date reminder sent update ignored (stale claim).', [
                'reminder_id' => $reminderId,
                'claim_token' => $claimToken,
            ]);

            return false;
        }

        return true;
    }

    private function markSendFailure(int $reminderId, string $claimToken): void
    {
        $updated = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->where('status', RequirementTargetDateReminderStatus::Processing->value)
            ->update([
                'status' => RequirementTargetDateReminderStatus::Failed->value,
                'skip_reason' => 'send_failed',
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            Log::warning('Requirement Target Date reminder send failure ignored (stale claim).', [
                'reminder_id' => $reminderId,
                'claim_token' => $claimToken,
            ]);
        }
    }

    /**
     * @return array{to_user_id: int|null, cc_user_ids: list<int>}
     */
    private function resolveRecipients(RecruitmentRequirement $requirement): array
    {
        $ccUsers = [];
        if ($requirement->creator instanceof User) {
            $ccUsers[] = $requirement->creator;
        }
        if (
            $requirement->submitter instanceof User
            && (int) $requirement->submitter->id !== (int) ($requirement->creator?->id ?? 0)
        ) {
            $ccUsers[] = $requirement->submitter;
        }

        return RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $requirement->assignedRecruiter instanceof User ? $requirement->assignedRecruiter : null,
            $ccUsers,
            primaryMustBeEligibleApprover: false,
        );
    }

    /**
     * @param  list<int>  $userIds
     * @return list<string>
     */
    private function emailsForUserIds(array $userIds, string $excludeEmail): array
    {
        $exclude = strtolower($excludeEmail);
        $emails = [];
        $seen = [$exclude => true];

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            $email = RequirementNotificationRecipients::usableEmail($user);
            if ($email === null) {
                continue;
            }

            $normalized = strtolower($email);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function details(RecruitmentRequirement $requirement, string $daysLabel): array
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
            ['label' => 'Status', 'value' => $requirement->status->label()],
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
            'label' => 'Deadline',
            'value' => $daysLabel,
        ];
        $details[] = [
            'label' => 'Assigned recruiter',
            'value' => (string) ($requirement->assignedRecruiter?->name ?? '—'),
        ];
        $details[] = [
            'label' => 'Requester',
            'value' => (string) ($requirement->creator?->name ?? '—'),
        ];

        return $details;
    }

    private function markSkipped(int $reminderId, string $claimToken, string $reason): void
    {
        $updated = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->where('status', RequirementTargetDateReminderStatus::Processing->value)
            ->update([
                'status' => RequirementTargetDateReminderStatus::Skipped->value,
                'skip_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            Log::warning('Requirement Target Date reminder skip ignored (stale claim).', [
                'reminder_id' => $reminderId,
                'claim_token' => $claimToken,
                'reason' => $reason,
            ]);

            return;
        }

        $this->skip($reason);
    }

    private function markRecoverableFailure(int $reminderId, string $claimToken, string $reason): void
    {
        $updated = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->where('status', RequirementTargetDateReminderStatus::Processing->value)
            ->update([
                'status' => RequirementTargetDateReminderStatus::Failed->value,
                'skip_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            Log::warning('Requirement Target Date reminder recoverable failure ignored (stale claim).', [
                'reminder_id' => $reminderId,
                'claim_token' => $claimToken,
                'reason' => $reason,
            ]);

            return;
        }

        $this->skip($reason);
    }

    private function skip(string $reason): void
    {
        Log::warning('Requirement Target Date reminder skipped.', [
            'reminder_id' => (int) ($this->payload['reminder_id'] ?? 0),
            'requirement_id' => (int) ($this->payload['requirement_id'] ?? 0),
            'company_id' => (int) ($this->payload['company_id'] ?? 0),
            'milestone' => (string) ($this->payload['milestone'] ?? ''),
            'target_date' => (string) ($this->payload['target_date'] ?? ''),
            'reason' => $reason,
        ]);
    }

    private function sanitizeExceptionMessage(string $message): string
    {
        $sanitized = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;

        return mb_substr($sanitized, 0, 500);
    }
}
