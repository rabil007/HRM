<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use Illuminate\Validation\Validator;

final class ValidatesCrewPlanningReliefLink
{
    /**
     * @param  array{
     *     company_id: int,
     *     relieves_crew_assignment_id: int|string|null,
     *     vessel_id: int|string|null,
     *     rank_id: int|string|null,
     *     employee_id: int|string|null
     * }  $data
     */
    public static function validate(Validator $validator, array $data, ?CrewPlanningAssignment $existing = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($existing?->crew_assignment_id !== null) {
            return;
        }

        $relievesId = $data['relieves_crew_assignment_id'];

        if ($relievesId === null || $relievesId === '') {
            return;
        }

        $relievesId = (int) $relievesId;

        $assignment = CrewAssignment::query()
            ->where('company_id', $data['company_id'])
            ->with('employee:id,rank_id')
            ->find($relievesId);

        if ($assignment === null) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The selected assignment could not be found.',
            );

            return;
        }

        $vesselId = $data['vessel_id'];
        $rankId = $data['rank_id'];

        if ($vesselId !== null && $vesselId !== '' && $assignment->vessel_id !== (int) $vesselId) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The relief assignment must be on the same vessel as the assignment being relieved.',
            );
        }

        $assignmentRankId = $assignment->rank_id ?? $assignment->employee?->rank_id;

        if ($rankId !== null && $rankId !== '' && $assignmentRankId !== null && (int) $assignmentRankId !== (int) $rankId) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The relief assignment must be for the same rank as the assignment being relieved.',
            );
        }

        $employeeId = $data['employee_id'];

        if ($employeeId !== null && $employeeId !== '' && (int) $employeeId === (int) $assignment->employee_id) {
            $validator->errors()->add(
                'employee_id',
                'The relief crew member cannot be the same person as the crew being relieved.',
            );
        }
    }
}
