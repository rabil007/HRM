<?php

namespace App\Jobs;

use App\Enums\Recruitment\CandidateReminderScheduleType;
use App\Enums\Recruitment\CandidateReminderStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Company;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateInternalReminder;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Recruitment\Candidates\CandidateOfferDateValidation;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class DeliverCandidateInternalReminderJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE_WORKER_TIMEOUT_SECONDS = 600;

    public const OVERLAP_LOCK_EXPIRE_SECONDS = self::QUEUE_WORKER_TIMEOUT_SECONDS + 60;

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
        return 'candidate-internal-reminder:'.$reminderId;
    }

    public static function calculateIntendedDeliveryDate(
        RecruitmentCandidateInternalReminder $reminder,
        RecruitmentCandidate $candidate,
        string $companyTimezone,
    ): ?string {
        if ($reminder->schedule_type === CandidateReminderScheduleType::Interview->value) {
            if ($candidate->interview_scheduled_at === null) {
                return null;
            }

            $scheduledDate = CarbonImmutable::instance($candidate->interview_scheduled_at)
                ->setTimezone($companyTimezone)
                ->startOfDay();

            return match ($reminder->milestone) {
                '1_day_before' => $scheduledDate->subDay()->toDateString(),
                'day_of' => $scheduledDate->toDateString(),
                default => null,
            };
        }

        if (in_array($reminder->schedule_type, [
            CandidateReminderScheduleType::Joining->value,
            CandidateReminderScheduleType::OverdueJoining->value,
        ], true)) {
            if ($candidate->expected_joining_date === null) {
                return null;
            }

            $dateStr = CandidateOfferDateValidation::extractDateOnlyString($candidate->expected_joining_date);
            if ($dateStr === null) {
                return null;
            }

            $expectedDate = CarbonImmutable::createFromFormat('!Y-m-d', $dateStr, $companyTimezone);

            if ($reminder->schedule_type === CandidateReminderScheduleType::Joining->value) {
                return match ($reminder->milestone) {
                    '7_days_before' => $expectedDate->subDays(7)->toDateString(),
                    '3_days_before' => $expectedDate->subDays(3)->toDateString(),
                    'day_of' => $expectedDate->toDateString(),
                    default => null,
                };
            }

            if (preg_match('/^overdue_(\d+)$/', (string) $reminder->milestone, $matches)) {
                $days = (int) $matches[1];

                return $expectedDate->addDays($days)->toDateString();
            }
        }

        return null;
    }

    public function handle(): void
    {
        $reminderId = (int) ($this->payload['reminder_id'] ?? 0);
        $companyId = (int) ($this->payload['company_id'] ?? 0);

        if ($reminderId <= 0 || $companyId <= 0) {
            return;
        }

        /** @var RecruitmentCandidateInternalReminder|null $reminder */
        $reminder = RecruitmentCandidateInternalReminder::query()
            ->where('id', $reminderId)
            ->where('company_id', $companyId)
            ->first();

        if ($reminder === null) {
            return;
        }

        if (in_array($reminder->status, [CandidateReminderStatus::Sent->value, CandidateReminderStatus::Skipped->value], true)) {
            return;
        }

        DB::transaction(function () use ($reminder): void {
            $locked = RecruitmentCandidateInternalReminder::query()
                ->where('id', $reminder->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || in_array($locked->status, [CandidateReminderStatus::Sent->value, CandidateReminderStatus::Skipped->value], true)) {
                return;
            }

            $company = Company::query()->where('id', (int) $locked->company_id)->first();
            if ($company === null || $company->status !== 'active') {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'inactive_company',
                ]);

                return;
            }

            $candidate = $locked->candidate()->with(['requirement'])->first();
            if ($candidate === null || (int) $candidate->company_id !== (int) $locked->company_id) {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'candidate_not_found',
                ]);

                return;
            }

            $requirement = $candidate->requirement;
            if ($requirement === null || in_array($requirement->status, [RequirementStatus::Cancelled, RequirementStatus::Completed], true)) {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'requirement_inactive',
                ]);

                return;
            }

            $recipient = User::query()->where('id', $locked->user_id)->first();
            if ($recipient === null || $recipient->status !== 'active' || $recipient->deleted_at !== null) {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'inactive_recipient',
                ]);

                return;
            }

            $hasActiveMembership = app(ResolveCompanyAccess::class)->canAccess($recipient, (int) $locked->company_id);
            if (! $hasActiveMembership) {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'revoked_membership',
                ]);

                return;
            }

            // Revalidate current recipient qualification against the requirement
            $isAssignedRecruiter = $requirement->assigned_to !== null && (int) $requirement->assigned_to === (int) $recipient->id;
            $isFallback = false;
            if ($requirement->submitted_by !== null) {
                $isFallback = (int) $requirement->submitted_by === (int) $recipient->id;
            } elseif ($requirement->created_by !== null) {
                $isFallback = (int) $requirement->created_by === (int) $recipient->id;
            }
            $isConfiguredCc = $requirement->notificationRecipients()
                ->where('user_id', $recipient->id)
                ->exists();

            if (! $isAssignedRecruiter && ! $isFallback && ! $isConfiguredCc) {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'unqualified_recipient',
                ]);

                return;
            }

            $companyTimezone = CompanyTimezone::forCompanyId((int) $locked->company_id);
            $todayLocal = CarbonImmutable::now($companyTimezone)->startOfDay()->toDateString();

            if ($locked->schedule_type === CandidateReminderScheduleType::Interview->value) {
                if ($candidate->stage !== CandidateStage::Interview || $candidate->interview_outcome !== null || $candidate->interview_scheduled_at === null) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'candidate_stage_or_outcome_changed',
                    ]);

                    return;
                }

                $currentScheduleKey = $candidate->interview_scheduled_at->format('Y-m-d H:i:s');
                if ($currentScheduleKey !== $locked->schedule_key) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'interview_rescheduled',
                    ]);

                    return;
                }

                $intendedDeliveryDate = self::calculateIntendedDeliveryDate($locked, $candidate, $companyTimezone);
                if ($intendedDeliveryDate === null) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'invalid_milestone',
                    ]);

                    return;
                }

                if ($todayLocal > $intendedDeliveryDate) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'milestone_expired',
                    ]);

                    return;
                }

                if ($todayLocal < $intendedDeliveryDate) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'premature_delivery',
                    ]);

                    return;
                }

                $scheduledInTz = CarbonImmutable::instance($candidate->interview_scheduled_at)
                    ->setTimezone($companyTimezone);
                $formattedTime = $scheduledInTz->format('d M Y H:i');

                $title = match ($locked->milestone) {
                    'day_of' => "Interview Today: {$candidate->name}",
                    default => "Interview Tomorrow: {$candidate->name}",
                };

                $summary = "Interview scheduled for candidate {$candidate->name} ({$candidate->position_title_snapshot}, Req: {$candidate->requirement_number_snapshot}) on {$formattedTime}.";
            } elseif (in_array($locked->schedule_type, [CandidateReminderScheduleType::Joining->value, CandidateReminderScheduleType::OverdueJoining->value], true)) {
                if ($candidate->stage !== CandidateStage::Joining || $candidate->expected_joining_date === null) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'candidate_stage_changed',
                    ]);

                    return;
                }

                $currentScheduleKey = $candidate->expected_joining_date->toDateString();
                if ($currentScheduleKey !== $locked->schedule_key) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'joining_rescheduled',
                    ]);

                    return;
                }

                $intendedDeliveryDate = self::calculateIntendedDeliveryDate($locked, $candidate, $companyTimezone);
                if ($intendedDeliveryDate === null) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'invalid_milestone',
                    ]);

                    return;
                }

                if ($todayLocal > $intendedDeliveryDate) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'milestone_expired',
                    ]);

                    return;
                }

                if ($todayLocal < $intendedDeliveryDate) {
                    $locked->update([
                        'status' => CandidateReminderStatus::Skipped->value,
                        'skip_reason' => 'premature_delivery',
                    ]);

                    return;
                }

                $expectedDate = $candidate->expected_joining_date->format('d M Y');

                if ($locked->schedule_type === CandidateReminderScheduleType::OverdueJoining->value) {
                    $daysOverdue = 1;
                    if (preg_match('/^overdue_(\d+)$/', (string) $locked->milestone, $matches)) {
                        $daysOverdue = (int) $matches[1];
                    }

                    $title = "Overdue Joining: {$candidate->name} ({$daysOverdue}d overdue)";
                    $summary = "Candidate {$candidate->name} ({$candidate->position_title_snapshot}, Req: {$candidate->requirement_number_snapshot}) was expected to join on {$expectedDate} and is currently {$daysOverdue} day(s) overdue.";
                } else {
                    $title = match ($locked->milestone) {
                        'day_of' => "Joining Today: {$candidate->name}",
                        '3_days_before' => "Joining in 3 days: {$candidate->name}",
                        default => "Upcoming Joining (7 days): {$candidate->name}",
                    };

                    $summary = "Candidate {$candidate->name} ({$candidate->position_title_snapshot}, Req: {$candidate->requirement_number_snapshot}) is expected to join on {$expectedDate}.";
                }
            } else {
                $locked->update([
                    'status' => CandidateReminderStatus::Skipped->value,
                    'skip_reason' => 'unknown_schedule_type',
                ]);

                return;
            }

            $url = route('organization.recruitment.candidates.show', $candidate->id);

            $locked->update([
                'status' => CandidateReminderStatus::Sent->value,
                'title' => $title,
                'summary' => $summary,
                'url' => $url,
                'sent_at' => now(),
            ]);
        });
    }
}
