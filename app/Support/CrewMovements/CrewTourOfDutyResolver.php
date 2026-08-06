<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewTourOfDutySource;
use App\Models\CrewRankPolicy;
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
     * Resolve Tour of Duty from trusted server-side company context.
     *
     * Precedence:
     * 1. Assignment-specific override
     * 2. Company-specific rank policy
     * 3. Global ranks.max_tour_of_duty_days
     * 4. No automatic calculation
     */
    public function resolve(
        int $companyId,
        int $rankId,
        CarbonInterface $actualJoinAt,
        ?int $assignmentOverrideDays = null,
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

        $days = null;
        $source = null;

        if ($assignmentOverrideDays !== null) {
            $this->assertTourDaysRange($assignmentOverrideDays, 'tour_of_duty_days');
            $days = $assignmentOverrideDays;
            $source = CrewTourOfDutySource::AssignmentOverride;
        } else {
            $policy = CrewRankPolicy::query()
                ->forCompany($companyId)
                ->active()
                ->where('rank_id', $rankId)
                ->first();

            if ($policy !== null) {
                $days = (int) $policy->tour_of_duty_days;
                $source = CrewTourOfDutySource::CompanyRankPolicy;
            } elseif ($rank->max_tour_of_duty_days !== null && (int) $rank->max_tour_of_duty_days > 0) {
                $days = (int) $rank->max_tour_of_duty_days;
                $source = CrewTourOfDutySource::GlobalRankDefault;
            }
        }

        $suggested = $days !== null
            ? $this->calculator->suggestedPlannedSignoff($actualJoinAt, $days, $timezone)
            : null;

        return new CrewTourOfDutyResult(
            tourOfDutyDays: $days,
            tourOfDutySource: $source,
            suggestedPlannedSignoffAt: $suggested,
            timezone: $timezone,
        );
    }

    /**
     * Preview resolution for Join Vessel form options (no join date required).
     *
     * @return array{
     *     rank_id: int,
     *     rank_name: string,
     *     global_tour_of_duty_days: int|null,
     *     company_tour_of_duty_days: int|null,
     *     resolved_tour_of_duty_days: int|null,
     *     resolved_tour_of_duty_source: string|null
     * }
     */
    public function previewForRank(int $companyId, Rank $rank): array
    {
        $policyDays = CrewRankPolicy::query()
            ->forCompany($companyId)
            ->active()
            ->where('rank_id', $rank->id)
            ->value('tour_of_duty_days');

        $globalDays = $rank->max_tour_of_duty_days !== null
            ? (int) $rank->max_tour_of_duty_days
            : null;

        $companyDays = $policyDays !== null ? (int) $policyDays : null;

        if ($companyDays !== null) {
            $resolvedDays = $companyDays;
            $resolvedSource = CrewTourOfDutySource::CompanyRankPolicy;
        } elseif ($globalDays !== null && $globalDays > 0) {
            $resolvedDays = $globalDays;
            $resolvedSource = CrewTourOfDutySource::GlobalRankDefault;
        } else {
            $resolvedDays = null;
            $resolvedSource = null;
        }

        return [
            'rank_id' => $rank->id,
            'rank_name' => $rank->name,
            'global_tour_of_duty_days' => $globalDays,
            'company_tour_of_duty_days' => $companyDays,
            'resolved_tour_of_duty_days' => $resolvedDays,
            'resolved_tour_of_duty_source' => $resolvedSource?->value,
        ];
    }

    private function assertTourDaysRange(int $days, string $field): void
    {
        if ($days < 1 || $days > 365) {
            throw ValidationException::withMessages([
                $field => 'Tour of Duty must be between 1 and 365 days.',
            ]);
        }
    }
}
