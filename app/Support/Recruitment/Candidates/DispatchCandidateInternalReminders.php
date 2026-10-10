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
use Illuminate\Support\Facades\DB;
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

        // Independent recovery sweep for stale queued or pending records
        $recovery = $this->recoverStaleRemindersForCompany($companyId);
        $queued += $recovery['recovered'];
        $skipped += $recovery['skipped'];

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

            $outcome = $this->claimAndEnqueue((int) $reminder->id, (int) $candidate->company_id);

            if ($outcome === 'enqueued') {
                $queued++;
            } else {
                $skipped++;
            }
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

    /**
     * Atomically claim a reminder and enqueue delivery if eligible.
     * Shared mechanism for normal dispatch and stale recovery sweeps.
     *
     * Under a row lock, rechecks current status and stale eligibility:
     * - Never overwrites terminal statuses (Sent or Skipped).
     * - Never overwrites a recently claimed Queued record.
     * - Only the successful claimant may enqueue delivery.
     * - In the event of enqueue failure, database transaction rolls back.
     *
     * @return 'enqueued'|'skipped'|'error'
     */
    public function claimAndEnqueue(int $reminderId, int $companyId): string
    {
        $staleQueuedThreshold = now()->subMinutes(self::STALE_QUEUED_TIMEOUT_MINUTES);
        $companyTimezone = CompanyTimezone::forCompanyId($companyId);
        $todayLocal = CarbonImmutable::now($companyTimezone)->startOfDay()->toDateString();

        try {
            return DB::transaction(function () use ($reminderId, $companyId, $staleQueuedThreshold, $companyTimezone, $todayLocal): string {
                /** @var RecruitmentCandidateInternalReminder|null $locked */
                $locked = RecruitmentCandidateInternalReminder::query()
                    ->where('id', $reminderId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    return 'skipped';
                }

                // 1. Never overwrite terminal statuses (Sent or Skipped)
                if (in_array($locked->status, [CandidateReminderStatus::Sent->value, CandidateReminderStatus::Skipped->value], true)) {
                    return 'skipped';
                }

                // 2. Recheck current status and stale eligibility under row lock
                if ($locked->status === CandidateReminderStatus::Queued->value) {
                    $isRecentlyClaimed = ($locked->claimed_at !== null && $locked->claimed_at->greaterThan($staleQueuedThreshold))
                        || ($locked->claimed_at === null && $locked->updated_at !== null && $locked->updated_at->greaterThan($staleQueuedThreshold));

                    if ($isRecentlyClaimed) {
                        return 'skipped';
                    }
                } elseif ($locked->status !== CandidateReminderStatus::Pending->value) {
                    return 'skipped';
                }

                // 3. Recheck parent requirement and candidate eligibility
                $candidate = $locked->candidate()->with(['requirement'])->first();
                if ($candidate === null || (int) $candidate->company_id !== $companyId) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'candidate_not_found',
                    ]);

                    return 'skipped';
                }

                $requirement = $candidate->requirement;
                if ($requirement === null || in_array($requirement->status, [RequirementStatus::Cancelled, RequirementStatus::Completed], true)) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'requirement_inactive',
                    ]);

                    return 'skipped';
                }

                // 4. Recheck candidate stage, outcome, and current schedule key
                if ($locked->schedule_type === CandidateReminderScheduleType::Interview->value) {
                    if ($candidate->stage !== CandidateStage::Interview || $candidate->interview_outcome !== null || $candidate->interview_scheduled_at === null) {
                        $locked->update([
                            'status' => CandidateReminderStatus::Skipped->value,
                            'skip_reason' => 'candidate_stage_or_outcome_changed',
                        ]);

                        return 'skipped';
                    }

                    $currentScheduleKey = $candidate->interview_scheduled_at->format('Y-m-d H:i:s');
                    if ($currentScheduleKey !== $locked->schedule_key) {
                        $locked->update([
                            'status' => CandidateReminderStatus::Skipped->value,
                            'skip_reason' => 'interview_rescheduled',
                        ]);

                        return 'skipped';
                    }
                } elseif (in_array($locked->schedule_type, [CandidateReminderScheduleType::Joining->value, CandidateReminderScheduleType::OverdueJoining->value], true)) {
                    if ($candidate->stage !== CandidateStage::Joining || $candidate->expected_joining_date === null) {
                        $locked->update([
                            'status' => CandidateReminderStatus::Skipped->value,
                            'skip_reason' => 'candidate_stage_changed',
                        ]);

                        return 'skipped';
                    }

                    $currentScheduleKey = $candidate->expected_joining_date->toDateString();
                    if ($currentScheduleKey !== $locked->schedule_key) {
                        $locked->update([
                            'status' => CandidateReminderStatus::Skipped->value,
                            'skip_reason' => 'joining_rescheduled',
                        ]);

                        return 'skipped';
                    }
                }

                // 5. Expiry checks against company timezone milestone date
                $intendedDeliveryDate = DeliverCandidateInternalReminderJob::calculateIntendedDeliveryDate($locked, $candidate, $companyTimezone);
                if ($intendedDeliveryDate === null || $todayLocal > $intendedDeliveryDate) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'milestone_expired',
                    ]);

                    return 'skipped';
                }

                if ($todayLocal < $intendedDeliveryDate) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'premature_delivery',
                    ]);

                    return 'skipped';
                }

                // 6. Claim record atomically
                $locked->update([
                    'status' => CandidateReminderStatus::Queued->value,
                    'claimed_at' => now(),
                ]);

                // 7. Enqueue delivery: only the successful claimant may enqueue
                DeliverCandidateInternalReminderJob::dispatch([
                    'reminder_id' => (int) $locked->id,
                    'company_id' => (int) $companyId,
                ]);

                return 'enqueued';
            });
        } catch (Throwable $e) {
            report($e);

            return 'error';
        }
    }

    /**
     * Independent bounded company-scoped recovery sweep for stale queued or pending records.
     *
     * @return array{recovered: int, skipped: int, errors: int}
     */
    public function recoverStaleRemindersForCompany(int $companyId, int $limit = 100): array
    {
        $staleQueuedThreshold = now()->subMinutes(self::STALE_QUEUED_TIMEOUT_MINUTES);

        $staleReminders = RecruitmentCandidateInternalReminder::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($staleQueuedThreshold): void {
                $query->where('status', CandidateReminderStatus::Pending->value)
                    ->orWhere(function ($q) use ($staleQueuedThreshold): void {
                        $q->where('status', CandidateReminderStatus::Queued->value)
                            ->where(function ($sub) use ($staleQueuedThreshold): void {
                                $sub->where('claimed_at', '<=', $staleQueuedThreshold)
                                    ->orWhere(function ($s2) use ($staleQueuedThreshold): void {
                                        $s2->whereNull('claimed_at')
                                            ->where('updated_at', '<=', $staleQueuedThreshold);
                                    });
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $recovered = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($staleReminders as $staleReminder) {
            $outcome = $this->claimAndEnqueue((int) $staleReminder->id, $companyId);
            if ($outcome === 'enqueued') {
                $recovered++;
            } elseif ($outcome === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }

        return [
            'recovered' => $recovered,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
