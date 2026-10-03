<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use Illuminate\Support\Collection;

/**
 * Batch-load active operational relief for source assignments.
 *
 * Prefer named Active CrewAssignment relief. Fall back to
 * CrewPlanningAssignment relief plans when no assignment exists.
 * Draft CrewAssignments are non-committed and do not count as operational relief.
 */
final class CrewReliefPlanningLoader
{
    /**
     * @param  list<int>  $sourceAssignmentIds
     * @return Collection<int, CrewAssignment|CrewPlanningAssignment> keyed by relieves_crew_assignment_id
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

        $resolver = new CrewReliefReadinessResolver;
        $activeBySource = collect();

        $assignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('relieves_crew_assignment_id', $ids)
            ->where('status', CrewAssignmentStatus::Active)
            ->with([
                'employee:id,company_id,name,employee_no',
                'vessel:id,company_id,name',
                'currentPhase',
                'relievedAssignment.employee:id,company_id,name,employee_no',
                'relievedAssignment.vessel:id,company_id,name',
                'relievedAssignment.position:id,company_id,title,max_tour_of_duty_days',
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($assignments as $assignment) {
            $sourceId = (int) $assignment->relieves_crew_assignment_id;

            if ($activeBySource->has($sourceId)) {
                continue;
            }

            if (! $resolver->isOperationallyActive($assignment)) {
                continue;
            }

            $activeBySource->put($sourceId, $assignment);
        }

        $remainingIds = array_values(array_filter(
            $ids,
            fn (int $id): bool => ! $activeBySource->has($id),
        ));

        if ($remainingIds === []) {
            return $activeBySource;
        }

        $plans = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('relieves_crew_assignment_id', $remainingIds)
            ->with([
                'employee:id,company_id,name,employee_no',
                'vessel:id,company_id,name',
                'crewAssignment.currentPhase',
                'crewAssignment.employee:id,company_id,name,employee_no',
                'relievedAssignment.employee:id,company_id,name,employee_no',
                'relievedAssignment.vessel:id,company_id,name',
                'relievedAssignment.position:id,company_id,title,max_tour_of_duty_days',
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($plans as $plan) {
            $sourceId = (int) $plan->relieves_crew_assignment_id;

            if ($activeBySource->has($sourceId)) {
                continue;
            }

            if (! $resolver->isOperationallyActive($plan)) {
                continue;
            }

            $activeBySource->put($sourceId, $plan);
        }

        return $activeBySource;
    }
}
