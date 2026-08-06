<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewPlanningAssignment;
use Illuminate\Support\Collection;

/**
 * Batch-load active operational relief Planning rows for source assignments.
 */
final class CrewReliefPlanningLoader
{
    /**
     * @param  list<int>  $sourceAssignmentIds
     * @return Collection<int, CrewPlanningAssignment> keyed by relieves_crew_assignment_id
     */
    public function forSourceAssignmentIds(int $companyId, array $sourceAssignmentIds): Collection
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $sourceAssignmentIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return collect();
        }

        $plans = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('relieves_crew_assignment_id', $ids)
            ->with([
                'employee:id,name,employee_no',
                'crewAssignment.currentPhase',
                'crewAssignment.employee:id,name,employee_no',
                'relievedAssignment.employee:id,name,employee_no',
                'relievedAssignment.vessel:id,name',
                'relievedAssignment.rank:id,name',
            ])
            ->orderByDesc('id')
            ->get();

        $resolver = new CrewReliefReadinessResolver;
        $activeBySource = collect();

        foreach ($plans as $plan) {
            $sourceId = (int) $plan->relieves_crew_assignment_id;

            if ($activeBySource->has($sourceId)) {
                continue;
            }

            if (! $resolver->isOperationallyActive($plan)) {
                continue;
            }

            // Cancelled linked assignments are not operationally active.
            if ($plan->crewAssignment?->status === CrewAssignmentStatus::Cancelled) {
                continue;
            }

            $activeBySource->put($sourceId, $plan);
        }

        return $activeBySource;
    }
}
