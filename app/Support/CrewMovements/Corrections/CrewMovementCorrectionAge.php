<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewMovementCorrection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class CrewMovementCorrectionAge
{
    public const NEEDS_ATTENTION_MIN_DAYS = 2;

    public const OVERDUE_MIN_DAYS = 4;

    public function forCorrection(
        CrewMovementCorrection $correction,
        string $timezone,
        ?CarbonInterface $now = null,
    ): array {
        if ($correction->status !== CrewMovementCorrectionStatus::Pending) {
            return [
                'pending_days' => null,
                'pending_age_label' => null,
                'age_status' => 'not_applicable',
                'age_status_label' => null,
                'needs_attention' => false,
                'is_overdue' => false,
                'overdue_days' => 0,
            ];
        }

        $requestedAt = $correction->requested_at ?? $correction->created_at;
        $today = CarbonImmutable::instance($now ?? now($timezone))
            ->setTimezone($timezone)
            ->startOfDay();
        $requestedDate = CarbonImmutable::instance($requestedAt ?? $today)
            ->setTimezone($timezone)
            ->startOfDay();
        $pendingDays = $requestedDate->greaterThan($today)
            ? 0
            : (int) $requestedDate->diffInDays($today);
        $ageStatus = $this->statusForAge($pendingDays);

        return [
            'pending_days' => $pendingDays,
            'pending_age_label' => match ($pendingDays) {
                0 => 'Pending today',
                1 => 'Pending for 1 day',
                default => sprintf('Pending for %d days', $pendingDays),
            },
            'age_status' => $ageStatus,
            'age_status_label' => match ($ageStatus) {
                'needs_attention' => 'Needs Attention',
                'overdue' => 'Overdue',
                default => 'On Time',
            },
            'needs_attention' => $ageStatus === 'needs_attention',
            'is_overdue' => $ageStatus === 'overdue',
            'overdue_days' => max(0, $pendingDays - self::OVERDUE_MIN_DAYS),
        ];
    }

    public function statusForAge(int $pendingDays): string
    {
        if ($pendingDays >= self::OVERDUE_MIN_DAYS) {
            return 'overdue';
        }

        if ($pendingDays >= self::NEEDS_ATTENTION_MIN_DAYS) {
            return 'needs_attention';
        }

        return 'on_time';
    }

    public function applyFilter(
        Builder $query,
        string $ageStatus,
        string $timezone,
        ?CarbonInterface $now = null,
    ): void {
        [$needsAttentionCutoff, $overdueCutoff] = $this->cutoffs($timezone, $now);
        $timestamp = 'COALESCE(requested_at, created_at)';

        $query->where('status', CrewMovementCorrectionStatus::Pending);

        match ($ageStatus) {
            'on_time' => $query->whereRaw("{$timestamp} >= ?", [$needsAttentionCutoff]),
            'needs_attention' => $query
                ->whereRaw("{$timestamp} < ?", [$needsAttentionCutoff])
                ->whereRaw("{$timestamp} >= ?", [$overdueCutoff]),
            'overdue' => $query->whereRaw("{$timestamp} < ?", [$overdueCutoff]),
            default => null,
        };
    }

    public function applyPriorityOrder(
        Builder $query,
        string $timezone,
        ?CarbonInterface $now = null,
    ): void {
        [$needsAttentionCutoff, $overdueCutoff] = $this->cutoffs($timezone, $now);
        $pending = CrewMovementCorrectionStatus::Pending->value;

        $query
            ->orderByRaw(
                'CASE
                    WHEN status = ? AND COALESCE(requested_at, created_at) < ? THEN 0
                    WHEN status = ? AND COALESCE(requested_at, created_at) < ? THEN 1
                    WHEN status = ? THEN 2
                    ELSE 3
                END',
                [$pending, $overdueCutoff, $pending, $needsAttentionCutoff, $pending],
            )
            ->orderByRaw(
                'CASE WHEN status = ? THEN COALESCE(requested_at, created_at) END ASC',
                [$pending],
            )
            ->orderByRaw('COALESCE(decided_at, updated_at) DESC')
            ->orderByDesc('id');
    }

    public function pendingCounts(
        Builder $query,
        string $timezone,
        ?CarbonInterface $now = null,
    ): array {
        [$needsAttentionCutoff, $overdueCutoff] = $this->cutoffs($timezone, $now);
        $pending = CrewMovementCorrectionStatus::Pending->value;
        $timestamp = 'COALESCE(requested_at, created_at)';

        $counts = $query
            ->where('status', $pending)
            ->selectRaw('COUNT(*) as pending_count')
            ->selectRaw(
                "SUM(CASE WHEN {$timestamp} < ? AND {$timestamp} >= ? THEN 1 ELSE 0 END) as needs_attention_count",
                [$needsAttentionCutoff, $overdueCutoff],
            )
            ->selectRaw(
                "SUM(CASE WHEN {$timestamp} < ? THEN 1 ELSE 0 END) as overdue_count",
                [$overdueCutoff],
            )
            ->first();

        return [
            'pending' => (int) ($counts?->pending_count ?? 0),
            'needs_attention' => (int) ($counts?->needs_attention_count ?? 0),
            'overdue' => (int) ($counts?->overdue_count ?? 0),
        ];
    }

    private function cutoffs(string $timezone, ?CarbonInterface $now = null): array
    {
        $today = CarbonImmutable::instance($now ?? now($timezone))
            ->setTimezone($timezone)
            ->startOfDay();

        return [
            $today
                ->subDays(self::NEEDS_ATTENTION_MIN_DAYS - 1)
                ->setTimezone((string) config('app.timezone', 'UTC')),
            $today
                ->subDays(self::OVERDUE_MIN_DAYS - 1)
                ->setTimezone((string) config('app.timezone', 'UTC')),
        ];
    }
}
