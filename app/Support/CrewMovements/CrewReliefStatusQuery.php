<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Company-scoped relief readiness filters and dashboard bucket counts.
 *
 * Filters resolve matching source assignment IDs with the same readiness
 * resolver used by presenters so dashboard card counts always equal the
 * linked Current Crew result set.
 */
final class CrewReliefStatusQuery
{
    public function __construct(
        private readonly CrewReliefReadinessResolver $resolver = new CrewReliefReadinessResolver,
        private readonly CrewReliefPlanningLoader $loader = new CrewReliefPlanningLoader,
    ) {}

    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public function applyStatusFilter(Builder $query, string $reliefStatus, int $companyId): Builder
    {
        $status = CrewReliefStatus::tryFrom($reliefStatus);

        if ($status === null) {
            return $query;
        }

        return $query->whereIn('id', $this->matchingIds($companyId, status: $status));
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public function applyRiskFilter(Builder $query, string $reliefRisk, int $companyId): Builder
    {
        $risk = CrewReliefRisk::tryFrom($reliefRisk);

        if ($risk === null) {
            return $query;
        }

        return $query->whereIn('id', $this->matchingIds($companyId, risk: $risk));
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public function applyNotReadyFilter(Builder $query, int $companyId): Builder
    {
        return $query->whereIn('id', $this->matchingIds($companyId, notReady: true));
    }

    /**
     * @return array{
     *     signoff_within_14_days_no_relief: int,
     *     relief_not_ready: int,
     *     relief_ready_to_join: int,
     *     critical_relief_risk: int
     * }
     */
    public function dashboardCounts(int $companyId): array
    {
        $resolved = $this->resolveActiveOnVessel($companyId);

        $counts = [
            'signoff_within_14_days_no_relief' => 0,
            'relief_not_ready' => 0,
            'relief_ready_to_join' => 0,
            'critical_relief_risk' => 0,
        ];

        foreach ($resolved as $result) {
            $daysUntil = $result->daysUntilSignoff;
            $within14 = $daysUntil !== null && $daysUntil >= 0 && $daysUntil <= 14;

            if ($within14 && $result->status === CrewReliefStatus::NoRelief) {
                $counts['signoff_within_14_days_no_relief']++;
            }

            if (in_array($result->status, CrewReliefStatus::notReady(), true)) {
                $counts['relief_not_ready']++;
            }

            if ($result->status === CrewReliefStatus::ReadyToJoin) {
                $counts['relief_ready_to_join']++;
            }

            if ($result->risk === CrewReliefRisk::Critical) {
                $counts['critical_relief_risk']++;
            }
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    public function matchingIds(
        int $companyId,
        ?CrewReliefStatus $status = null,
        ?CrewReliefRisk $risk = null,
        bool $notReady = false,
        bool $within14NoRelief = false,
    ): array {
        $ids = [];

        foreach ($this->resolveActiveOnVessel($companyId) as $assignmentId => $result) {
            if ($status !== null && $result->status !== $status) {
                continue;
            }

            if ($risk !== null && $result->risk !== $risk) {
                continue;
            }

            if ($notReady && ! in_array($result->status, CrewReliefStatus::notReady(), true)) {
                continue;
            }

            if ($within14NoRelief) {
                $daysUntil = $result->daysUntilSignoff;
                $within14 = $daysUntil !== null && $daysUntil >= 0 && $daysUntil <= 14;

                if (! $within14 || $result->status !== CrewReliefStatus::NoRelief) {
                    continue;
                }
            }

            $ids[] = (int) $assignmentId;
        }

        return $ids === [] ? [0] : $ids;
    }

    /**
     * @return Collection<int, CrewReliefReadinessResult> keyed by source assignment id
     */
    public function resolveActiveOnVessel(int $companyId): Collection
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $assignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereHas('currentPhase', function (Builder $phase): void {
                $phase->where('phase_code', CrewPhaseCode::OnVessel->value)
                    ->where('status', CrewPhaseStatus::Active->value);
            })
            ->get(['id', 'company_id', 'planned_signoff_at']);

        $plans = $this->loader->forSourceAssignmentIds(
            $companyId,
            $assignments->pluck('id')->all(),
        );

        $resolved = collect();

        foreach ($assignments as $assignment) {
            $resolved->put(
                (int) $assignment->id,
                $this->resolver->forPreloadedPlan(
                    $assignment,
                    $plans->get((int) $assignment->id),
                    $today,
                    $timezone,
                ),
            );
        }

        return $resolved;
    }
}
