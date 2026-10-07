<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Enums\Recruitment\RequirementTargetDateReminderMilestone;
use App\Enums\Recruitment\RequirementTargetDateReminderStatus;
use App\Jobs\DeliverRequirementTargetDateReminderJob;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementTargetDateReminder;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class DispatchRequirementTargetDateReminders
{
    public const LOCAL_DISPATCH_HOUR = 9;

    public const STALE_PROCESSING_TIMEOUT_MINUTES = 15;

    public const STALE_QUEUED_TIMEOUT_MINUTES = 15;

    /**
     * @return array{
     *     companies_checked: int,
     *     reminders_queued: int,
     *     skipped: int,
     *     errors: int
     * }
     */
    public function dispatchAll(bool $force = false, ?int $onlyCompanyId = null): array
    {
        $query = Company::query()
            ->where('status', 'active')
            ->orderBy('id');

        if ($onlyCompanyId !== null && $onlyCompanyId > 0) {
            $query->whereKey($onlyCompanyId);
        }

        $totals = [
            'companies_checked' => 0,
            'reminders_queued' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $query->chunkById(50, function (Collection $companies) use ($force, &$totals): void {
            foreach ($companies as $company) {
                $totals['companies_checked']++;

                try {
                    $result = $this->forCompany((int) $company->id, $force);
                    $totals['reminders_queued'] += $result['queued'];
                    $totals['skipped'] += $result['skipped'];
                } catch (Throwable $exception) {
                    report($exception);
                    $totals['errors']++;
                    Log::error('Requirement Target Date reminder company dispatch failed.', [
                        'company_id' => (int) $company->id,
                        'exception_class' => $exception::class,
                        'exception_message' => mb_substr($exception->getMessage(), 0, 500),
                    ]);
                }
            }
        });

        return $totals;
    }

    /**
     * @return array{queued: int, skipped: int, reason?: string}
     */
    public function forCompany(int $companyId, bool $force = false): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $nowLocal = CarbonImmutable::now($timezone);

        if (! $force && $nowLocal->hour < self::LOCAL_DISPATCH_HOUR) {
            return [
                'queued' => 0,
                'skipped' => 0,
                'reason' => 'outside_local_dispatch_hour',
            ];
        }

        $todayLocal = $nowLocal->toDateString();
        $queued = 0;
        $skipped = 0;

        foreach (RequirementTargetDateReminderMilestone::cases() as $milestone) {
            $targetDate = $nowLocal
                ->startOfDay()
                ->addDays($milestone->daysBeforeTarget())
                ->toDateString();

            $requirements = RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->whereDate('required_by_date', $targetDate)
                ->whereIn('status', [
                    RequirementStatus::Open->value,
                    RequirementStatus::OnHold->value,
                ])
                ->with([
                    'assignedRecruiter:id,name,email,status,deleted_at',
                    'creator:id,name,email,status,deleted_at',
                    'submitter:id,name,email,status,deleted_at',
                ])
                ->orderBy('id')
                ->get();

            foreach ($requirements as $requirement) {
                $result = $this->queueReminderForRequirement(
                    $requirement,
                    $milestone,
                    $targetDate,
                    $todayLocal,
                );

                if ($result === 'queued') {
                    $queued++;
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    private function queueReminderForRequirement(
        RecruitmentRequirement $requirement,
        RequirementTargetDateReminderMilestone $milestone,
        string $targetDate,
        string $evaluationDate,
    ): string {
        $recipients = $this->resolveRecipients($requirement);

        if ($recipients['to_user_id'] === null) {
            Log::info('Requirement Target Date reminder skipped — no valid primary recipient.', [
                'company_id' => (int) $requirement->company_id,
                'requirement_id' => (int) $requirement->id,
                'milestone' => $milestone->value,
                'target_date' => $targetDate,
            ]);

            return 'skipped';
        }

        $deliveryKey = RequirementTargetDateReminderDeliveryKey::forUser($recipients['to_user_id']);

        try {
            $reminder = RecruitmentRequirementTargetDateReminder::query()->firstOrCreate(
                [
                    'company_id' => (int) $requirement->company_id,
                    'recruitment_requirement_id' => (int) $requirement->id,
                    'target_date' => $targetDate,
                    'milestone' => $milestone->value,
                    'delivery_key' => $deliveryKey,
                ],
                [
                    'status' => RequirementTargetDateReminderStatus::Pending->value,
                    'primary_recipient_user_id' => $recipients['to_user_id'],
                    'cc_user_ids' => $recipients['cc_user_ids'],
                ],
            );
        } catch (QueryException $exception) {
            // Concurrent firstOrCreate races on the unique key.
            $reminder = RecruitmentRequirementTargetDateReminder::query()
                ->where('company_id', (int) $requirement->company_id)
                ->where('recruitment_requirement_id', (int) $requirement->id)
                ->whereDate('target_date', $targetDate)
                ->where('milestone', $milestone->value)
                ->where('delivery_key', $deliveryKey)
                ->first();

            if ($reminder === null) {
                throw $exception;
            }
        }

        if ($reminder->status === RequirementTargetDateReminderStatus::Sent) {
            return 'skipped';
        }

        if ($reminder->status === RequirementTargetDateReminderStatus::Skipped) {
            return 'skipped';
        }

        if ($reminder->status === RequirementTargetDateReminderStatus::Queued) {
            if (! $this->reclaimStaleQueued((int) $reminder->id)) {
                return 'skipped';
            }

            $reminder = $reminder->fresh();

            if ($reminder === null) {
                return 'skipped';
            }
        }

        if ($reminder->status === RequirementTargetDateReminderStatus::Processing) {
            if (! $this->reclaimStaleProcessing((int) $reminder->id)) {
                return 'skipped';
            }

            $reminder = $reminder->fresh();

            if ($reminder === null) {
                return 'skipped';
            }
        }

        $claimToken = (string) Str::uuid();

        $claimed = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminder->id)
            ->whereIn('status', [
                RequirementTargetDateReminderStatus::Pending->value,
                RequirementTargetDateReminderStatus::Failed->value,
            ])
            ->update([
                'status' => RequirementTargetDateReminderStatus::Queued->value,
                'claim_token' => $claimToken,
                'claimed_at' => null,
                'primary_recipient_user_id' => $recipients['to_user_id'],
                'cc_user_ids' => $recipients['cc_user_ids'],
                'skip_reason' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return 'skipped';
        }

        $payload = [
            'reminder_id' => (int) $reminder->id,
            'company_id' => (int) $requirement->company_id,
            'requirement_id' => (int) $requirement->id,
            'target_date' => $targetDate,
            'milestone' => $milestone->value,
            'delivery_key' => $deliveryKey,
            'evaluation_date' => $evaluationDate,
            'primary_recipient_user_id' => $recipients['to_user_id'],
            'cc_user_ids' => $recipients['cc_user_ids'],
            'claim_token' => $claimToken,
        ];

        try {
            $this->dispatchDeliveryJob($payload);
        } catch (Throwable $exception) {
            $this->recoverQueuedDispatchFailure((int) $reminder->id, $claimToken);

            throw $exception;
        }

        return 'queued';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchDeliveryJob(array $payload): void
    {
        DeliverRequirementTargetDateReminderJob::dispatch($payload);
    }

    public function recoverQueuedDispatchFailure(int $reminderId, string $claimToken): bool
    {
        $updated = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('claim_token', $claimToken)
            ->where('status', RequirementTargetDateReminderStatus::Queued->value)
            ->update([
                'status' => RequirementTargetDateReminderStatus::Failed->value,
                'skip_reason' => 'queue_dispatch_failed',
                'updated_at' => now(),
            ]);

        return $updated > 0;
    }

    private function reclaimStaleProcessing(int $reminderId): bool
    {
        $threshold = now()->subMinutes(self::STALE_PROCESSING_TIMEOUT_MINUTES);

        $reclaimed = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('status', RequirementTargetDateReminderStatus::Processing->value)
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<', $threshold);
            })
            ->update([
                'status' => RequirementTargetDateReminderStatus::Failed->value,
                'skip_reason' => 'processing_stale',
                'updated_at' => now(),
            ]);

        return $reclaimed > 0;
    }

    private function reclaimStaleQueued(int $reminderId): bool
    {
        $threshold = now()->subMinutes(self::STALE_QUEUED_TIMEOUT_MINUTES);

        $reclaimed = RecruitmentRequirementTargetDateReminder::query()
            ->whereKey($reminderId)
            ->where('status', RequirementTargetDateReminderStatus::Queued->value)
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => RequirementTargetDateReminderStatus::Failed->value,
                'skip_reason' => 'queued_stale',
                'updated_at' => now(),
            ]);

        return $reclaimed > 0;
    }

    /**
     * @return array{to_user_id: int|null, cc_user_ids: list<int>}
     */
    private function resolveRecipients(RecruitmentRequirement $requirement): array
    {
        $primary = $requirement->assignedRecruiter;
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
            $primary instanceof User ? $primary : null,
            $ccUsers,
            primaryMustBeEligibleApprover: false,
        );
    }
}
