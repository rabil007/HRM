<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class CalculateActiveRecruitmentDuration
{
    /**
     * @return array{
     *     recruitment_started_at: string|null,
     *     recruitment_started_at_formatted: string|null,
     *     recruitment_start_source: 'approved_at'|'opened_at'|null,
     *     active_recruitment_seconds: int|null,
     *     active_recruitment_days: float|null,
     *     on_hold_seconds: int,
     *     closed_seconds: int,
     *     recruitment_duration_label: string|null,
     *     recruitment_clock_state: 'not_started'|'running'|'paused'|'completed'|'cancelled',
     *     duration_is_estimated: bool,
     *     duration_estimate_note: string|null,
     *     approved_at: string|null,
     *     approved_at_formatted: string|null,
     *     approved_by_name: string|null,
     * }
     */
    public static function for(
        RecruitmentRequirement $requirement,
        ?CarbonInterface $now = null,
    ): array {
        $now ??= Carbon::now();

        $approvedAt = $requirement->approved_at;
        $legacyOpenedAt = $requirement->opened_at;
        $start = $approvedAt ?? $legacyOpenedAt;
        $startSource = $approvedAt !== null
            ? 'approved_at'
            : ($legacyOpenedAt !== null ? 'opened_at' : null);

        $clockState = self::clockState($requirement, $start);

        if ($start === null) {
            return self::emptyPayload($clockState, $requirement);
        }

        $windowEnd = match ($requirement->status) {
            RequirementStatus::Completed => $requirement->completed_at ?? $now,
            RequirementStatus::Cancelled => $requirement->cancelled_at ?? $now,
            default => $now,
        };

        if ($windowEnd->lt($start)) {
            $windowEnd = $start->copy();
        }

        $timeline = self::accumulateTimeline($requirement, $start, $windowEnd);

        $activeSeconds = max(0, $timeline['active_seconds']);
        $onHoldSeconds = max(0, $timeline['on_hold_seconds']);
        $closedSeconds = max(0, $timeline['closed_seconds']);
        $activeDays = round($activeSeconds / 86400, 2);

        return [
            'recruitment_started_at' => $start->toIso8601String(),
            'recruitment_started_at_formatted' => $start->format('d-m-Y H:i'),
            'recruitment_start_source' => $startSource,
            'active_recruitment_seconds' => $activeSeconds,
            'active_recruitment_days' => $activeDays,
            'on_hold_seconds' => $onHoldSeconds,
            'closed_seconds' => $closedSeconds,
            'recruitment_duration_label' => self::formatDuration($activeSeconds),
            'recruitment_clock_state' => $clockState,
            'duration_is_estimated' => $timeline['is_estimated'],
            'duration_estimate_note' => $timeline['estimate_note'],
            'approved_at' => $requirement->approved_at?->toIso8601String(),
            'approved_at_formatted' => $requirement->approved_at?->format('d-m-Y H:i'),
            'approved_by_name' => $requirement->approver?->name,
        ];
    }

    private static function clockState(
        RecruitmentRequirement $requirement,
        ?CarbonInterface $start,
    ): string {
        if ($requirement->status === RequirementStatus::Completed) {
            return 'completed';
        }

        if ($requirement->status === RequirementStatus::Cancelled) {
            return 'cancelled';
        }

        if ($start === null) {
            return 'not_started';
        }

        if ($requirement->status === RequirementStatus::OnHold) {
            return 'paused';
        }

        return 'running';
    }

    /**
     * @return array{
     *     recruitment_started_at: null,
     *     recruitment_started_at_formatted: null,
     *     recruitment_start_source: null,
     *     active_recruitment_seconds: null,
     *     active_recruitment_days: null,
     *     on_hold_seconds: int,
     *     closed_seconds: int,
     *     recruitment_duration_label: null,
     *     recruitment_clock_state: string,
     *     duration_is_estimated: bool,
     *     duration_estimate_note: null,
     *     approved_at: string|null,
     *     approved_at_formatted: string|null,
     *     approved_by_name: string|null,
     * }
     */
    private static function emptyPayload(string $clockState, RecruitmentRequirement $requirement): array
    {
        return [
            'recruitment_started_at' => null,
            'recruitment_started_at_formatted' => null,
            'recruitment_start_source' => null,
            'active_recruitment_seconds' => null,
            'active_recruitment_days' => null,
            'on_hold_seconds' => 0,
            'closed_seconds' => 0,
            'recruitment_duration_label' => null,
            'recruitment_clock_state' => $clockState,
            'duration_is_estimated' => false,
            'duration_estimate_note' => null,
            'approved_at' => $requirement->approved_at?->toIso8601String(),
            'approved_at_formatted' => $requirement->approved_at?->format('d-m-Y H:i'),
            'approved_by_name' => $requirement->approver?->name,
        ];
    }

    /**
     * Walk status transitions and count only Open intervals as active recruitment time.
     * On Hold, Completed, and Cancelled intervals are excluded from active time.
     *
     * @return array{
     *     active_seconds: int,
     *     on_hold_seconds: int,
     *     closed_seconds: int,
     *     is_estimated: bool,
     *     estimate_note: string|null
     * }
     */
    private static function accumulateTimeline(
        RecruitmentRequirement $requirement,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $transitions = RecruitmentRequirementStatusTransition::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->where('company_id', $requirement->company_id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'created_at']);

        $isEstimated = false;
        $estimateNote = null;

        // After approval / open start, clock begins in an active (Open) state.
        $currentBucket = 'active';
        $segmentStartedAt = $start->copy();
        $active = 0;
        $onHold = 0;
        $closed = 0;

        // Legacy On Hold without hold transition history: estimate hold start from updated_at when bounded.
        if (
            $requirement->status === RequirementStatus::OnHold
            && $transitions->where('to_status', RequirementStatus::OnHold->value)->isEmpty()
        ) {
            $estimatedHoldStart = $requirement->updated_at !== null
                ? Carbon::parse($requirement->updated_at)
                : $start->copy();

            if ($estimatedHoldStart->lt($start)) {
                $estimatedHoldStart = $start->copy();
            }
            if ($estimatedHoldStart->gt($end)) {
                $estimatedHoldStart = $end->copy();
            }

            $active = max(0, (int) $start->diffInSeconds($estimatedHoldStart, false));
            $onHold = max(0, (int) $estimatedHoldStart->diffInSeconds($end, false));
            $isEstimated = true;
            $estimateNote = 'On-hold duration is estimated from the last update timestamp because no hold transition history exists.';

            return [
                'active_seconds' => $active,
                'on_hold_seconds' => $onHold,
                'closed_seconds' => 0,
                'is_estimated' => $isEstimated,
                'estimate_note' => $estimateNote,
            ];
        }

        foreach ($transitions as $transition) {
            $at = Carbon::parse($transition->created_at);
            if ($at->lt($segmentStartedAt)) {
                continue;
            }

            $elapsed = max(0, (int) $segmentStartedAt->diffInSeconds($at, false));
            if ($currentBucket === 'active') {
                $active += $elapsed;
            } elseif ($currentBucket === 'on_hold') {
                $onHold += $elapsed;
            } else {
                $closed += $elapsed;
            }

            $currentBucket = self::bucketForStatus((string) $transition->to_status);
            $segmentStartedAt = $at;
        }

        $tail = max(0, (int) $segmentStartedAt->diffInSeconds($end, false));
        if ($currentBucket === 'active') {
            $active += $tail;
        } elseif ($currentBucket === 'on_hold') {
            $onHold += $tail;
        } else {
            $closed += $tail;
        }

        // If currently completed/cancelled but the last transition wasn't recorded before end,
        // closed_seconds already includes the terminal wait via windowEnd = completed_at/cancelled_at.
        return [
            'active_seconds' => $active,
            'on_hold_seconds' => $onHold,
            'closed_seconds' => $closed,
            'is_estimated' => $isEstimated,
            'estimate_note' => $estimateNote,
        ];
    }

    private static function bucketForStatus(string $status): string
    {
        return match ($status) {
            RequirementStatus::OnHold->value => 'on_hold',
            RequirementStatus::Completed->value,
            RequirementStatus::Cancelled->value => 'closed',
            default => 'active',
        };
    }

    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days === 1 ? '1 day' : "{$days} days";
        }
        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 hour' : "{$hours} hours";
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        return $parts === [] ? '0 minutes' : implode(', ', $parts);
    }
}
