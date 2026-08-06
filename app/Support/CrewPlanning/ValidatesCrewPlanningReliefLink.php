<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
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
        $companyId = (int) $data['company_id'];

        $assignment = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->with(['employee:id,rank_id', 'currentPhase'])
            ->find($relievesId);

        if ($assignment === null) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The selected assignment could not be found.',
            );

            return;
        }

        if ($assignment->status !== CrewAssignmentStatus::Active
            || $assignment->currentPhase?->phase_code !== CrewPhaseCode::OnVessel
            || $assignment->currentPhase?->status !== CrewPhaseStatus::Active) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'Relief can only be planned for an active On Vessel assignment.',
            );

            return;
        }

        if ($assignment->vessel_id === null || $assignment->rank_id === null) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The assignment being relieved must have a vessel and rank.',
            );

            return;
        }

        $vesselId = $data['vessel_id'];
        $rankId = $data['rank_id'];

        if ($vesselId === null || $vesselId === '') {
            $validator->errors()->add(
                'vessel_id',
                'A vessel is required when planning relief.',
            );
        } elseif ((int) $vesselId !== (int) $assignment->vessel_id) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The relief assignment must be on the same vessel as the assignment being relieved.',
            );
        }

        $assignmentRankId = $assignment->rank_id ?? $assignment->employee?->rank_id;

        if ($rankId === null || $rankId === '') {
            $validator->errors()->add(
                'rank_id',
                'A rank is required when planning relief.',
            );
        } elseif ($assignmentRankId !== null && (int) $assignmentRankId !== (int) $rankId) {
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

        $duplicate = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('relieves_crew_assignment_id', $relievesId)
            ->when($existing?->id !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->where(function ($query): void {
                $query->whereNull('crew_assignment_id')
                    ->orWhereHas('crewAssignment', function ($linked): void {
                        $linked->where('status', '!=', CrewAssignmentStatus::Cancelled->value);
                    });
            })
            ->exists();

        if ($duplicate) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'An active relief plan already exists for this assignment.',
            );
        }
    }
}
