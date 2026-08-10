<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewTourStatus;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CrewTourOfDutyCalculator
{
    /**
     * Suggested planned sign-off local date = actual P4 join local date + tour days.
     */
    public function suggestedPlannedSignoff(
        CarbonInterface $actualJoinAt,
        int $tourOfDutyDays,
        string $timezone,
    ): CarbonImmutable {
        return CarbonImmutable::parse(
            $actualJoinAt->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay()->addDays($tourOfDutyDays);
    }

    /**
     * Whole local calendar days between join and "today" (or an explicit end).
     */
    public function daysOnboard(
        CarbonInterface $actualJoinAt,
        string $timezone,
        ?CarbonInterface $asOf = null,
    ): int {
        $from = CarbonImmutable::parse(
            $actualJoinAt->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        $to = CarbonImmutable::parse(
            ($asOf ?? now($timezone))->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        return (int) $from->diffInDays($to, false);
    }

    public function currentDutyDay(int $daysOnboard): int
    {
        return $daysOnboard + 1;
    }

    public function remainingTourDays(
        CarbonInterface $plannedSignoffAt,
        string $timezone,
        ?CarbonInterface $asOf = null,
    ): int {
        $signoff = CarbonImmutable::parse(
            $plannedSignoffAt->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        $today = CarbonImmutable::parse(
            ($asOf ?? now($timezone))->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        return (int) $today->diffInDays($signoff, false);
    }

    /**
     * Progress ratio of days onboard over applied tour days.
     * Display percentage may be clamped; raw ratio is returned unclamped.
     */
    public function tourProgressPercent(int $daysOnboard, int $tourOfDutyDays): ?float
    {
        if ($tourOfDutyDays <= 0) {
            return null;
        }

        return round(($daysOnboard / $tourOfDutyDays) * 100, 1);
    }

    public function clampDisplayPercent(?float $percent): ?float
    {
        if ($percent === null) {
            return null;
        }

        return max(0.0, min(100.0, $percent));
    }

    public function resolveStatus(
        ?int $tourOfDutyDays,
        ?CarbonInterface $plannedSignoffAt,
        string $timezone,
        ?CarbonInterface $asOf = null,
    ): CrewTourStatus {
        if ($plannedSignoffAt === null) {
            if ($tourOfDutyDays === null || $tourOfDutyDays <= 0) {
                return CrewTourStatus::MissingTourRule;
            }

            return CrewTourStatus::MissingSignoff;
        }

        $remaining = $this->remainingTourDays($plannedSignoffAt, $timezone, $asOf);

        if ($remaining < 0) {
            return CrewTourStatus::Overdue;
        }

        if ($remaining === 0) {
            return CrewTourStatus::DueToday;
        }

        if ($remaining <= 7) {
            return CrewTourStatus::DueWithin7Days;
        }

        if ($remaining <= 14) {
            return CrewTourStatus::DueWithin14Days;
        }

        if ($remaining <= 30) {
            return CrewTourStatus::DueWithin30Days;
        }

        return CrewTourStatus::Normal;
    }

    public function timezoneForCompany(int $companyId): string
    {
        return CompanyTimezone::forCompanyId($companyId);
    }
}
