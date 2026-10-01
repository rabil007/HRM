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
     *     recruitment_duration_label: string|null,
     *     recruitment_clock_state: 'not_started'|'running'|'paused'|'completed'|'cancelled',
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

        $end = match ($requirement->status) {
            RequirementStatus::Completed => $requirement->completed_at ?? $now,
            RequirementStatus::Cancelled => $requirement->cancelled_at ?? $now,
            default => $now,
        };

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $onHoldSeconds = self::onHoldSeconds($requirement, $start, $end);
        $totalSeconds = max(0, (int) $start->diffInSeconds($end, false));
        $activeSeconds = max(0, $totalSeconds - $onHoldSeconds);
        $activeDays = round($activeSeconds / 86400, 2);

        return [
            'recruitment_started_at' => $start->toIso8601String(),
            'recruitment_started_at_formatted' => $start->format('d-m-Y H:i'),
            'recruitment_start_source' => $startSource,
            'active_recruitment_seconds' => $activeSeconds,
            'active_recruitment_days' => $activeDays,
            'on_hold_seconds' => $onHoldSeconds,
            'recruitment_duration_label' => self::formatDuration($activeSeconds),
            'recruitment_clock_state' => $clockState,
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
     *     recruitment_duration_label: null,
     *     recruitment_clock_state: string,
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
            'recruitment_duration_label' => null,
            'recruitment_clock_state' => $clockState,
            'approved_at' => $requirement->approved_at?->toIso8601String(),
            'approved_at_formatted' => $requirement->approved_at?->format('d-m-Y H:i'),
            'approved_by_name' => $requirement->approver?->name,
        ];
    }

    private static function onHoldSeconds(
        RecruitmentRequirement $requirement,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        $transitions = RecruitmentRequirementStatusTransition::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->where('company_id', $requirement->company_id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'created_at']);

        $seconds = 0;
        $holdStartedAt = null;

        if ($requirement->status === RequirementStatus::OnHold) {
            // If already on hold before the window (unlikely for approved start), open at start.
            $lastBefore = RecruitmentRequirementStatusTransition::query()
                ->where('recruitment_requirement_id', $requirement->id)
                ->where('company_id', $requirement->company_id)
                ->where('created_at', '<', $start)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['to_status']);

            if ($lastBefore?->to_status === RequirementStatus::OnHold->value) {
                $holdStartedAt = $start->copy();
            }
        }

        foreach ($transitions as $transition) {
            $at = Carbon::parse($transition->created_at);

            if ($transition->to_status === RequirementStatus::OnHold->value && $holdStartedAt === null) {
                $holdStartedAt = $at;
            }

            if (
                $holdStartedAt !== null
                && $transition->from_status === RequirementStatus::OnHold->value
                && $transition->to_status !== RequirementStatus::OnHold->value
            ) {
                $seconds += max(0, (int) $holdStartedAt->diffInSeconds($at, false));
                $holdStartedAt = null;
            }
        }

        if ($holdStartedAt !== null) {
            $seconds += max(0, (int) $holdStartedAt->diffInSeconds($end, false));
        }

        return $seconds;
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
