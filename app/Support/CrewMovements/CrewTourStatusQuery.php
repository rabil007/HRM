<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTourStatus;
use App\Models\CrewAssignment;
use App\Support\Employees\ActiveEmployeeConstraint;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-scoped Tour of Duty status filters for Current Crew and dashboard.
 */
final class CrewTourStatusQuery
{
    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
    ) {}

    /**
     * Apply a tour_status filter to an Active P4 assignment query.
     *
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public function applyFilter(Builder $query, string $tourStatus, int $companyId): Builder
    {
        $status = CrewTourStatus::tryFrom($tourStatus);

        if ($status === null || ! in_array($status, CrewTourStatus::filterable(), true)) {
            return $query;
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->toDateString();

        $query->where('status', CrewAssignmentStatus::Active)
            ->whereHas('currentPhase', function (Builder $phase) {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->where('status', CrewPhaseStatus::Active->value);
            });

        ActiveEmployeeConstraint::whereHas($query, $companyId);

        return match ($status) {
            CrewTourStatus::MissingTourRule => $query
                ->whereNull('planned_signoff_at')
                ->where(function (Builder $q): void {
                    $q->whereNull('tour_of_duty_days')
                        ->orWhere('tour_of_duty_days', '<=', 0);
                }),
            CrewTourStatus::MissingSignoff => $query
                ->whereNull('planned_signoff_at')
                ->where('tour_of_duty_days', '>', 0),
            CrewTourStatus::Overdue => $query
                ->whereNotNull('planned_signoff_at')
                ->whereDate('planned_signoff_at', '<', $today),
            CrewTourStatus::DueToday => $query
                ->whereNotNull('planned_signoff_at')
                ->whereDate('planned_signoff_at', '=', $today),
            // Cumulative windows match dashboard "within N days" card counts
            // (include due today and all nearer exclusive buckets).
            CrewTourStatus::DueWithin7Days => $query
                ->whereNotNull('planned_signoff_at')
                ->whereDate('planned_signoff_at', '>=', $today)
                ->whereDate('planned_signoff_at', '<=', CarbonImmutable::parse($today, $timezone)->addDays(7)->toDateString()),
            CrewTourStatus::DueWithin14Days => $query
                ->whereNotNull('planned_signoff_at')
                ->whereDate('planned_signoff_at', '>=', $today)
                ->whereDate('planned_signoff_at', '<=', CarbonImmutable::parse($today, $timezone)->addDays(14)->toDateString()),
            CrewTourStatus::DueWithin30Days => $query
                ->whereNotNull('planned_signoff_at')
                ->whereDate('planned_signoff_at', '>=', $today)
                ->whereDate('planned_signoff_at', '<=', CarbonImmutable::parse($today, $timezone)->addDays(30)->toDateString()),
            default => $query,
        };
    }

    /**
     * Dashboard / attention bucket counts for active P4 assignments.
     *
     * @return array{
     *     due_within_30_days: int,
     *     due_within_14_days: int,
     *     due_within_7_days: int,
     *     due_today: int,
     *     overdue: int,
     *     missing_tour_rule: int,
     *     missing_signoff: int
     * }
     */
    public function bucketCounts(int $companyId): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $assignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereHas('currentPhase', function (Builder $phase) {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->where('status', CrewPhaseStatus::Active->value);
            });

        ActiveEmployeeConstraint::whereHas($assignments, $companyId);

        $assignments = $assignments->get(['id', 'company_id', 'tour_of_duty_days', 'planned_signoff_at']);

        $counts = [
            'due_within_30_days' => 0,
            'due_within_14_days' => 0,
            'due_within_7_days' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'missing_tour_rule' => 0,
            'missing_signoff' => 0,
        ];

        foreach ($assignments as $assignment) {
            $status = $this->calculator->resolveStatus(
                $assignment->tour_of_duty_days !== null ? (int) $assignment->tour_of_duty_days : null,
                $assignment->planned_signoff_at,
                $timezone,
                $today,
            );

            match ($status) {
                CrewTourStatus::DueWithin30Days => $counts['due_within_30_days']++,
                CrewTourStatus::DueWithin14Days => $counts['due_within_14_days']++,
                CrewTourStatus::DueWithin7Days => $counts['due_within_7_days']++,
                CrewTourStatus::DueToday => $counts['due_today']++,
                CrewTourStatus::Overdue => $counts['overdue']++,
                CrewTourStatus::MissingTourRule => $counts['missing_tour_rule']++,
                CrewTourStatus::MissingSignoff => $counts['missing_signoff']++,
                default => null,
            };
        }

        return $counts;
    }
}
