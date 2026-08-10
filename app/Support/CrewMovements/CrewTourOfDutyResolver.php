<?php

namespace App\Support\CrewMovements;

use App\Models\Rank;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class CrewTourOfDutyResolver
{
    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
    ) {}

    /**
     * Resolve Tour of Duty from global Rank Master data.
     */
    public function resolve(
        int $companyId,
        int $rankId,
        CarbonInterface $actualJoinAt,
    ): CrewTourOfDutyResult {
        $timezone = CompanyTimezone::forCompanyId($companyId);

        $rank = Rank::query()
            ->whereKey($rankId)
            ->first();

        if ($rank === null) {
            throw ValidationException::withMessages([
                'rank_id' => 'The selected rank is invalid for this company.',
            ]);
        }

        $days = $rank->max_tour_of_duty_days !== null && (int) $rank->max_tour_of_duty_days > 0
            ? (int) $rank->max_tour_of_duty_days
            : null;

        $suggested = $days !== null
            ? $this->calculator->suggestedPlannedSignoff($actualJoinAt, $days, $timezone)
            : null;

        return new CrewTourOfDutyResult(
            tourOfDutyDays: $days,
            suggestedPlannedSignoffAt: $suggested,
            timezone: $timezone,
        );
    }
}
