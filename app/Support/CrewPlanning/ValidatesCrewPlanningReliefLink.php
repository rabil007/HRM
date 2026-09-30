<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Positions\RankPositionBridge;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

final class ValidatesCrewPlanningReliefLink
{
    public static function assertOrThrow(CrewPlanningAssignment $planning): void
    {
        $validator = ValidatorFacade::make([], []);

        self::validate($validator, [
            'company_id' => (int) $planning->company_id,
            'relieves_crew_assignment_id' => $planning->relieves_crew_assignment_id,
            'vessel_id' => $planning->vessel_id,
            'position_id' => RankPositionBridge::resolveCrewAssignmentPositionId(
                (int) $planning->company_id,
                $planning->position_id !== null ? (int) $planning->position_id : null,
                $planning->rank_id !== null ? (int) $planning->rank_id : null,
            ),
            'employee_id' => $planning->employee_id,
        ], $planning);

        if ($validator->errors()->isNotEmpty()) {
            throw CrewMovementException::make(
                (string) $validator->errors()->first(),
                'planning_relief_invalid',
            );
        }
    }

    /**
     * Early Form Request validation for relief links.
     *
     * Always runs when `relieves_crew_assignment_id` is present, including vacant slots
     * (`employee_id` null). Authoritative duplicate enforcement lives in
     * {@see SaveCrewPlanningAssignment} after `lockForUpdate()`.
     *
     * @param  array{
     *     company_id: int,
     *     relieves_crew_assignment_id: int|string|null,
     *     vessel_id: int|string|null,
     *     position_id: int|string|null,
     *     employee_id: int|string|null
     * }  $data
     */
    public static function validate(
        Validator $validator,
        array $data,
        ?CrewPlanningAssignment $existing = null,
        ?User $user = null,
    ): void {
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
            ->with(['employee:id', 'currentPhase', 'position:id,title'])
            ->find($relievesId);

        if ($assignment === null) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The selected assignment could not be found.',
            );

            return;
        }

        if ($user !== null && $assignment->employee !== null
            && ! EmployeeVisibilityScope::canAccess($user, $assignment->employee, $companyId)) {
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

        $assignmentPositionId = RankPositionBridge::resolveCrewAssignmentPositionId(
            $companyId,
            $assignment->position_id !== null ? (int) $assignment->position_id : null,
            $assignment->rank_id !== null ? (int) $assignment->rank_id : null,
        );

        if ($assignment->vessel_id === null || $assignmentPositionId === null) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The assignment being relieved must have a vessel and position.',
            );

            return;
        }

        $vesselId = $data['vessel_id'];
        $planningPositionRaw = $data['position_id'] ?? null;
        $planningPositionId = $planningPositionRaw !== null && $planningPositionRaw !== ''
            ? (int) $planningPositionRaw
            : null;

        if ($planningPositionId === null && $existing !== null) {
            $planningPositionId = RankPositionBridge::resolveCrewAssignmentPositionId(
                $companyId,
                $existing->position_id !== null ? (int) $existing->position_id : null,
                $existing->rank_id !== null ? (int) $existing->rank_id : null,
            );
        }

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

        if ($planningPositionId === null) {
            $validator->errors()->add(
                'position_id',
                'A position is required when planning relief.',
            );
        } elseif ((int) $planningPositionId !== (int) $assignmentPositionId) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'The relief assignment must be for the same position as the assignment being relieved.',
            );
        }

        $employeeId = $data['employee_id'];

        if ($employeeId !== null && $employeeId !== '' && (int) $employeeId === (int) $assignment->employee_id) {
            $validator->errors()->add(
                'employee_id',
                'The relief crew member cannot be the same person as the crew being relieved.',
            );
        }

        if ((new CrewReliefReadinessResolver)->hasActiveOperationalRelief(
            $companyId,
            $relievesId,
            $existing?->id !== null ? (int) $existing->id : null,
        )) {
            $validator->errors()->add(
                'relieves_crew_assignment_id',
                'An active relief plan already exists for this assignment.',
            );
        }
    }
}
