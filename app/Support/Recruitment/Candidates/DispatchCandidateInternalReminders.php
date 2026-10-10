<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateReminderScheduleType;
use App\Enums\Recruitment\CandidateReminderStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementStatus;
use App\Jobs\DeliverCandidateInternalReminderJob;
use App\Models\Company;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateInternalReminder;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchCandidateInternalReminders
{
    public const LOCAL_DISPATCH_HOUR = 9;

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
                    Log::error('Candidate internal reminder company dispatch failed.', [
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
        $storageTimezone = (string) config('app.timezone', 'UTC');
        $nowLocal = CarbonImmutable::now($timezone);

        if (! $force && $nowLocal->hour < self::LOCAL_DISPATCH_HOUR) {
            return [
                'queued' => 0,
                'skipped' => 0,
                'reason' => 'outside_local_dispatch_hour',
            ];
        }

        $queued = 0;
        $skipped = 0;

        // 1. Interview reminders: 1 day before and on scheduled date
        $interviewMilestones = [
            '1_day_before' => $nowLocal->addDay()->toDateString(),
            'day_of' => $nowLocal->toDateString(),
        ];

        foreach ($interviewMilestones as $milestone => $targetDate) {
            $startOfDayStorage = CarbonImmutable::parse($targetDate, $timezone)->startOfDay()->setTimezone($storageTimezone);
            $endOfDayStorage = CarbonImmutable::parse($targetDate, $timezone)->endOfDay()->setTimezone($storageTimezone);

            $candidates = RecruitmentCandidate::query()
                ->where('company_id', $companyId)
                ->where('stage', CandidateStage::Interview->value)
                ->whereNull('interview_outcome')
                ->whereBetween('interview_scheduled_at', [$startOfDayStorage, $endOfDayStorage])
                ->whereHas('requirement', fn ($q) => $q->whereIn('status', [RequirementStatus::Open->value, RequirementStatus::OnHold->value]))
                ->with([
                    'requirement.assignedRecruiter:id,name,email,status,deleted_at',
                    'requirement.submitter:id,name,email,status,deleted_at',
                    'requirement.creator:id,name,email,status,deleted_at',
                    'requirement.notificationRecipients.user:id,name,email,status,deleted_at',
                ])
                ->get();

            foreach ($candidates as $candidate) {
                if ($candidate->interview_scheduled_at === null) {
                    continue;
                }

                $scheduleKey = $candidate->interview_scheduled_at->format('Y-m-d H:i:s');
                $result = $this->dispatchForCandidate(
                    $candidate,
                    CandidateReminderScheduleType::Interview,
                    $scheduleKey,
                    $milestone,
                    $targetDate,
                );

                $queued += $result['queued'];
                $skipped += $result['skipped'];
            }
        }

        // 2. Joining reminders: 7 days before, 3 days before, and day of
        $joiningMilestones = [
            '7_days_before' => $nowLocal->addDays(7)->toDateString(),
            '3_days_before' => $nowLocal->addDays(3)->toDateString(),
            'day_of' => $nowLocal->toDateString(),
        ];

        foreach ($joiningMilestones as $milestone => $targetDate) {
            $candidates = RecruitmentCandidate::query()
                ->where('company_id', $companyId)
                ->where('stage', CandidateStage::Joining->value)
                ->whereDate('expected_joining_date', $targetDate)
                ->whereHas('requirement', fn ($q) => $q->whereIn('status', [RequirementStatus::Open->value, RequirementStatus::OnHold->value]))
                ->with([
                    'requirement.assignedRecruiter:id,name,email,status,deleted_at',
                    'requirement.submitter:id,name,email,status,deleted_at',
                    'requirement.creator:id,name,email,status,deleted_at',
                    'requirement.notificationRecipients.user:id,name,email,status,deleted_at',
                ])
                ->get();

            foreach ($candidates as $candidate) {
                if ($candidate->expected_joining_date === null) {
                    continue;
                }

                $scheduleKey = $candidate->expected_joining_date->toDateString();
                $result = $this->dispatchForCandidate(
                    $candidate,
                    CandidateReminderScheduleType::Joining,
                    $scheduleKey,
                    $milestone,
                    $targetDate,
                );

                $queued += $result['queued'];
                $skipped += $result['skipped'];
            }
        }

        // 3. Overdue joining reminders: once per company-local day, max 7 days overdue
        for ($dayNumber = 1; $dayNumber <= 7; $dayNumber++) {
            $targetDate = $nowLocal->subDays($dayNumber)->toDateString();
            $milestone = "overdue_{$dayNumber}";

            $candidates = RecruitmentCandidate::query()
                ->where('company_id', $companyId)
                ->where('stage', CandidateStage::Joining->value)
                ->whereDate('expected_joining_date', $targetDate)
                ->whereHas('requirement', fn ($q) => $q->whereIn('status', [RequirementStatus::Open->value, RequirementStatus::OnHold->value]))
                ->with([
                    'requirement.assignedRecruiter:id,name,email,status,deleted_at',
                    'requirement.submitter:id,name,email,status,deleted_at',
                    'requirement.creator:id,name,email,status,deleted_at',
                    'requirement.notificationRecipients.user:id,name,email,status,deleted_at',
                ])
                ->get();

            foreach ($candidates as $candidate) {
                if ($candidate->expected_joining_date === null) {
                    continue;
                }

                $scheduleKey = $candidate->expected_joining_date->toDateString();
                $result = $this->dispatchForCandidate(
                    $candidate,
                    CandidateReminderScheduleType::OverdueJoining,
                    $scheduleKey,
                    $milestone,
                    $targetDate,
                );

                $queued += $result['queued'];
                $skipped += $result['skipped'];
            }
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{queued: int, skipped: int}
     */
    private function dispatchForCandidate(
        RecruitmentCandidate $candidate,
        CandidateReminderScheduleType $type,
        string $scheduleKey,
        string $milestone,
        string $targetDate,
    ): array {
        $recipients = $this->resolveRecipients($candidate);
        if ($recipients->isEmpty()) {
            return ['queued' => 0, 'skipped' => 1];
        }

        $queued = 0;
        $skipped = 0;

        foreach ($recipients as $recipient) {
            $deliveryKey = "user_{$recipient->id}";

            try {
                $reminder = RecruitmentCandidateInternalReminder::query()->firstOrCreate(
                    [
                        'company_id' => (int) $candidate->company_id,
                        'recruitment_candidate_id' => (int) $candidate->id,
                        'schedule_type' => $type->value,
                        'schedule_key' => $scheduleKey,
                        'milestone' => $milestone,
                        'user_id' => (int) $recipient->id,
                    ],
                    [
                        'target_date' => $targetDate,
                        'delivery_key' => $deliveryKey,
                        'status' => CandidateReminderStatus::Pending->value,
                    ],
                );
            } catch (QueryException $exception) {
                $reminder = RecruitmentCandidateInternalReminder::query()
                    ->where('company_id', (int) $candidate->company_id)
                    ->where('recruitment_candidate_id', (int) $candidate->id)
                    ->where('schedule_type', $type->value)
                    ->where('schedule_key', $scheduleKey)
                    ->where('milestone', $milestone)
                    ->where('user_id', (int) $recipient->id)
                    ->first();

                if ($reminder === null) {
                    throw $exception;
                }
            }

            if ($reminder->status === CandidateReminderStatus::Sent->value || $reminder->status === CandidateReminderStatus::Skipped->value) {
                $skipped++;

                continue;
            }

            if ($reminder->status === CandidateReminderStatus::Queued->value) {
                if (! $this->reclaimStaleQueued((int) $reminder->id)) {
                    $skipped++;

                    continue;
                }

                $reminder->refresh();
            }

            $reminder->update([
                'status' => CandidateReminderStatus::Queued->value,
                'claimed_at' => now(),
            ]);

            DeliverCandidateInternalReminderJob::dispatch([
                'reminder_id' => (int) $reminder->id,
                'company_id' => (int) $candidate->company_id,
            ]);

            $queued++;
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(RecruitmentCandidate $candidate): Collection
    {
        $requirement = $candidate->requirement;
        if ($requirement === null) {
            return collect();
        }

        $recipients = collect();

        // 1. Current assigned recruiter
        if ($requirement->assignedRecruiter !== null) {
            $recipients->push($requirement->assignedRecruiter);
        }

        // 2. Submitter where eligible (or creator)
        if ($requirement->submitter !== null) {
            $recipients->push($requirement->submitter);
        } elseif ($requirement->creator !== null) {
            $recipients->push($requirement->creator);
        }

        // 3. Active configured notification recipients
        foreach ($requirement->notificationRecipients as $notifRecipient) {
            if ($notifRecipient->user !== null) {
                $recipients->push($notifRecipient->user);
            }
        }

        // Deduplicate and filter active, non-deleted users only
        return $recipients
            ->filter(fn (User $user): bool => $user->status === 'active' && $user->deleted_at === null)
            ->unique('id')
            ->values();
    }

    private function reclaimStaleQueued(int $reminderId): bool
    {
        $staleThreshold = now()->subMinutes(self::STALE_QUEUED_TIMEOUT_MINUTES);

        $affected = RecruitmentCandidateInternalReminder::query()
            ->where('id', $reminderId)
            ->where('status', CandidateReminderStatus::Queued->value)
            ->where('updated_at', '<=', $staleThreshold)
            ->update([
                'status' => CandidateReminderStatus::Pending->value,
                'claimed_at' => null,
            ]);

        return $affected > 0;
    }
}
