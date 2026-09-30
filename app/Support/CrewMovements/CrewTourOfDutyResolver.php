<?php

namespace App\Support\CrewMovements;

use App\Models\Position;
use App\Support\Positions\RankPositionBridge;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class CrewTourOfDutyResolver
{
    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
    ) {}

    /**
     * Resolve Tour of Duty from the assignment Position master data.
     *
     * Uses the assignment-specific Position — never fall back to Employee.position_id.
     */
    public function resolve(
        int $companyId,
        int $positionId,
        CarbonInterface $actualJoinAt,
    ): CrewTourOfDutyResult {
        $timezone = CompanyTimezone::forCompanyId($companyId);

        $position = Position::query()
            ->where('company_id', $companyId)
            ->whereKey($positionId)
            ->whereNull('deleted_at')
            ->first();

        if ($position === null) {
            throw ValidationException::withMessages([
                'position_id' => 'The selected position is invalid for this company.',
            ]);
        }

        $days = $position->max_tour_of_duty_days !== null && (int) $position->max_tour_of_duty_days > 0
            ? (int) $position->max_tour_of_duty_days
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

    /**
     * @deprecated Temporary Phase 2 alias — prefer resolve() with position_id.
     */
    public function resolveFromRank(
        int $companyId,
        int $rankId,
        CarbonInterface $actualJoinAt,
    ): CrewTourOfDutyResult {
        $positionId = RankPositionBridge::positionIdForRank($companyId, $rankId);

        if ($positionId === null) {
            throw ValidationException::withMessages([
                'position_id' => 'The selected rank has no usable Position mapping for this company.',
            ]);
        }

        return $this->resolve($companyId, $positionId, $actualJoinAt);
    }
}
