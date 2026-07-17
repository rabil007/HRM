<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewMovementCorrection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class CrewMovementCorrectionSla
{
    public const ATTENTION_MIN_DAYS = 2;

    public const OVERDUE_MIN_DAYS = 4;

    public function forCorrection(
        CrewMovementCorrection $correction,
        string $timezone,
        ?CarbonInterface $now = null,
    ): array {
        if ($correction->status !== CrewMovementCorrectionStatus::Pending) {
            return [
                'age_days' => null,
                'age_label' => $correction->status->label(),
                'sla_status' => 'not_applicable',
                'sla_label' => 'Not applicable',
                'is_attention' => false,
                'is_overdue' => false,
                'days_beyond_sla' => 0,
            ];
        }

        $requestedAt = $correction->requested_at ?? $correction->created_at;
        $today = CarbonImmutable::instance($now ?? now($timezone))
            ->setTimezone($timezone)
            ->startOfDay();
        $requestedDate = CarbonImmutable::instance($requestedAt ?? $today)
            ->setTimezone($timezone)
            ->startOfDay();
        $ageDays = $requestedDate->greaterThan($today)
            ? 0
            : (int) $requestedDate->diffInDays($today);
        $slaStatus = $this->statusForAge($ageDays);

        return [
            'age_days' => $ageDays,
            'age_label' => match ($ageDays) {
                0 => 'Pending today',
                1 => 'Pending for 1 day',
                default => sprintf('Pending for %d days', $ageDays),
            },
            'sla_status' => $slaStatus,
            'sla_label' => match ($slaStatus) {
                'attention' => 'Attention',
                'overdue' => 'Overdue',
                default => 'Normal',
            },
            'is_attention' => $slaStatus === 'attention',
            'is_overdue' => $slaStatus === 'overdue',
            'days_beyond_sla' => max(0, $ageDays - self::OVERDUE_MIN_DAYS),
        ];
    }

    public function statusForAge(int $ageDays): string
    {
        if ($ageDays >= self::OVERDUE_MIN_DAYS) {
            return 'overdue';
        }

        if ($ageDays >= self::ATTENTION_MIN_DAYS) {
            return 'attention';
        }

        return 'normal';
    }

    public function applyFilter(
        Builder $query,
        string $slaStatus,
        string $timezone,
        ?CarbonInterface $now = null,
    ): void {
        [$attentionCutoff, $overdueCutoff] = $this->cutoffs($timezone, $now);
        $timestamp = 'COALESCE(requested_at, created_at)';

        $query->where('status', CrewMovementCorrectionStatus::Pending);

        match ($slaStatus) {
            'normal' => $query->whereRaw("{$timestamp} >= ?", [$attentionCutoff]),
            'attention' => $query
                ->whereRaw("{$timestamp} < ?", [$attentionCutoff])
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
        [$attentionCutoff, $overdueCutoff] = $this->cutoffs($timezone, $now);
        $pending = CrewMovementCorrectionStatus::Pending->value;

        $query
            ->orderByRaw(
                'CASE
                    WHEN status = ? AND COALESCE(requested_at, created_at) < ? THEN 0
                    WHEN status = ? AND COALESCE(requested_at, created_at) < ? THEN 1
                    WHEN status = ? THEN 2
                    ELSE 3
                END',
                [$pending, $overdueCutoff, $pending, $attentionCutoff, $pending],
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
        [$attentionCutoff, $overdueCutoff] = $this->cutoffs($timezone, $now);
        $pending = CrewMovementCorrectionStatus::Pending->value;
        $timestamp = 'COALESCE(requested_at, created_at)';

        $counts = $query
            ->where('status', $pending)
            ->selectRaw('COUNT(*) as pending_count')
            ->selectRaw(
                "SUM(CASE WHEN {$timestamp} < ? AND {$timestamp} >= ? THEN 1 ELSE 0 END) as attention_count",
                [$attentionCutoff, $overdueCutoff],
            )
            ->selectRaw(
                "SUM(CASE WHEN {$timestamp} < ? THEN 1 ELSE 0 END) as overdue_count",
                [$overdueCutoff],
            )
            ->first();

        return [
            'pending' => (int) ($counts?->pending_count ?? 0),
            'attention' => (int) ($counts?->attention_count ?? 0),
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
                ->subDays(self::ATTENTION_MIN_DAYS - 1)
                ->setTimezone((string) config('app.timezone', 'UTC')),
            $today
                ->subDays(self::OVERDUE_MIN_DAYS - 1)
                ->setTimezone((string) config('app.timezone', 'UTC')),
        ];
    }
}
