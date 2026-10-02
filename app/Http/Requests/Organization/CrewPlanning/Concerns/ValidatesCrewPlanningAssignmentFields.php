<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewPlanning\ValidatesCrewPlanningReliefLink;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesCrewPlanningAssignmentFields
{
    /**
     * @return array<int, mixed>
     */
    protected function crewPlanningEmployeeIdRule(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'nullable',
            'integer',
            ActiveCompanyEmployeeRule::exists($companyId, $this->user()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function crewPlanningRelievesAssignmentIdRule(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'nullable',
            'integer',
            Rule::exists('crew_assignments', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = (int) $this->attributes->get('current_company_id');

            $assignment = $this->route('assignment');
            $existing = $assignment instanceof CrewPlanningAssignment ? $assignment : null;

            $assignmentPositionId = $this->has('position_id')
                ? $this->input('position_id')
                : null;

            if (($assignmentPositionId === null || $assignmentPositionId === '') && $existing !== null) {
                $assignmentPositionId = CrewPositionCatalog::resolveCrewAssignmentPositionId($companyId, $existing->position_id !== null ? (int) $existing->position_id : null);
            }

            $vesselId = $this->has('vessel_id')
                ? $this->input('vessel_id')
                : $existing?->vessel_id;

            if ($vesselId !== null && $vesselId !== '' && ! $validator->errors()->has('vessel_id')) {
                ClientAssignmentRules::vesselBelongsToClient(
                    $validator,
                    $companyId,
                    null,
                    (int) $vesselId,
                    requireAssignedClient: true,
                );
            }

            $arrival = $this->has('planned_arrival_date')
                ? $this->input('planned_arrival_date')
                : $existing?->planned_arrival_date?->toDateString();
            $join = $this->has('planned_join_date')
                ? $this->input('planned_join_date')
                : $existing?->planned_join_date?->toDateString();
            $leave = $this->has('planned_leave_date')
                ? $this->input('planned_leave_date')
                : $existing?->planned_leave_date?->toDateString();

            if ($arrival !== null && $arrival !== '' && $join !== null && $join !== '') {
                if ($arrival > $join) {
                    $validator->errors()->add('planned_arrival_date', 'Arrival Date cannot be after Expected Vessel Join.');
                }
            }

            if ($join !== null && $join !== '' && $leave !== null && $leave !== '') {
                if ($leave < $join) {
                    $validator->errors()->add('planned_leave_date', 'Expected Sign-off cannot be before Expected Vessel Join.');
                }
            }

            $employeeId = $this->has('employee_id')
                ? $this->input('employee_id')
                : ($existing?->employee_id !== null ? (int) $existing->employee_id : null);
            $employeeId = $employeeId !== null && $employeeId !== '' ? (int) $employeeId : null;

            ValidatesCrewPlanningReliefLink::validate($validator, [
                'company_id' => $companyId,
                'relieves_crew_assignment_id' => $this->has('relieves_crew_assignment_id')
                    ? $this->input('relieves_crew_assignment_id')
                    : $existing?->relieves_crew_assignment_id,
                'vessel_id' => $vesselId,
                'position_id' => $assignmentPositionId,
                'employee_id' => $employeeId,
            ], $existing, $this->user());

            if ($employeeId !== null && ! $validator->errors()->has('employee_id') && $join !== null && $join !== '' && $leave !== null && $leave !== '') {
                $timezone = CompanyTimezone::forCompanyId($companyId);
                $conflictContext = new CrewAssignmentConflictContext(
                    companyId: $companyId,
                    employeeId: $employeeId,
                    action: 'plan',
                    plannedJoinAt: CarbonImmutable::parse($join, $timezone)->startOfDay(),
                    plannedSignoffAt: CarbonImmutable::parse($leave, $timezone)->endOfDay(),
                    plannedArrivalAt: $arrival !== null && $arrival !== '' ? CarbonImmutable::parse($arrival, $timezone)->startOfDay() : null,
                    vesselId: $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null,
                    positionId: $assignmentPositionId !== null ? (int) $assignmentPositionId : null,
                    relievesCrewAssignmentId: $this->has('relieves_crew_assignment_id')
                        ? ($this->input('relieves_crew_assignment_id') !== null && $this->input('relieves_crew_assignment_id') !== '' ? (int) $this->input('relieves_crew_assignment_id') : null)
                        : $existing?->relieves_crew_assignment_id,
                    currentAssignmentId: null,
                    currentPlanningAssignmentId: $existing?->id,
                    actor: $this->user(),
                );

                $result = (new CrewAssignmentConflictEvaluator)->evaluate($conflictContext);
                if ($result->blocking) {
                    $validator->errors()->add('employee_id', $result->message);
                    $validator->errors()->add('conflict', json_encode($result->toArray()));
                }
            }
        });
    }
}
