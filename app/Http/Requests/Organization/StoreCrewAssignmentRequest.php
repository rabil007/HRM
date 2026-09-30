<?php

namespace App\Http\Requests\Organization;

use App\Enums\CrewAssignmentSubmissionIntent;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return match ($this->submissionIntent()) {
            CrewAssignmentSubmissionIntent::Plan => $user->can('crew_operations.planning.create'),
            CrewAssignmentSubmissionIntent::Start => $user->can('crew_operations.assignments.create')
                && $user->can('crew_operations.movements.perform'),
            CrewAssignmentSubmissionIntent::Draft => $user->can('crew_operations.assignments.create'),
        };
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        if ($companyId < 1) {
            $companyId = (int) ($this->user()?->current_company_id ?? 0);
        }
        $vesselId = $this->input('vessel_id');
        $clientId = $this->input('client_id');
        $intent = $this->input('submission_intent');
        $merge = [];

        if ($intent === null || $intent === '') {
            $intent = CrewAssignmentSubmissionIntent::Draft->value;
            $merge['submission_intent'] = $intent;
        }

        $planningAssignmentId = $this->input('planning_assignment_id');
        if ($planningAssignmentId !== null && $planningAssignmentId !== '' && $companyId > 0) {
            $slot = CrewPlanningAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $planningAssignmentId)
                ->first(['id', 'vessel_id', 'position_id']);

            if ($slot !== null) {
                if ($slot->vessel_id !== null) {
                    $merge['vessel_id'] = (int) $slot->vessel_id;
                    $vesselId = $merge['vessel_id'];
                }

                if ($slot->position_id !== null) {
                    $merge['position_id'] = (int) $slot->position_id;
                }
            }
        }

        if (($clientId === null || $clientId === '')
            && $vesselId !== null
            && $vesselId !== ''
            && $companyId > 0) {
            $resolved = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vesselId);

            if ($resolved !== null) {
                $merge['client_id'] = $resolved;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $isPlanIntent = $this->submissionIntent() === CrewAssignmentSubmissionIntent::Plan;

        return [
            'submission_intent' => ['required', 'string', Rule::in(CrewAssignmentSubmissionIntent::values())],
            'employee_id' => [
                'required',
                'integer',
                ActiveCompanyEmployeeRule::exists($companyId, $this->user()),
            ],
            'position_id' => ['nullable', 'integer', CrewPositionCatalog::existsCrewPositionRule($companyId)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vessel_id' => [$isPlanIntent ? 'required' : 'nullable', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'planned_arrival_at' => ['nullable', 'date'],
            'planned_join_at' => [$isPlanIntent ? 'required' : 'nullable', 'date'],
            'planned_signoff_at' => [$isPlanIntent ? 'required' : 'nullable', 'date'],
            'relieves_crew_assignment_id' => ['nullable', 'integer', Rule::exists('crew_assignments', 'id')->where('company_id', $companyId)],
            'planning_assignment_id' => ['nullable', 'integer', Rule::exists('crew_planning_assignments', 'id')->where('company_id', $companyId)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'planned_join_at.required' => 'Expected Vessel Join is required when saving as Planned.',
            'planned_signoff_at.required' => 'Expected Sign-off is required when saving as Planned.',
            'vessel_id.required' => 'Vessel is required when saving as Planned.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');
            $clientId = $this->input('client_id');
            $vesselId = $this->input('vessel_id');

            ClientAssignmentRules::vesselBelongsToClient(
                $validator,
                $companyId,
                $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null,
            );

            if ($this->submissionIntent() === CrewAssignmentSubmissionIntent::Plan) {
                $positionId = $this->input('position_id');
                $effectivePositionId = $positionId !== null && $positionId !== '' ? (int) $positionId : null;

                if ($effectivePositionId === null) {
                    $employeeId = (int) $this->input('employee_id');
                    $employeePositionId = Employee::query()
                        ->where('company_id', $companyId)
                        ->whereKey($employeeId)
                        ->value('position_id');

                    $effectivePositionId = $employeePositionId !== null ? (int) $employeePositionId : null;
                }

                if ($effectivePositionId === null) {
                    $validator->errors()->add('position_id', 'Position is required when saving as Planned.');
                }
            }

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $plannedArrival = $this->input('planned_arrival_at');
            $plannedJoin = $this->input('planned_join_at');
            $plannedSignoff = $this->input('planned_signoff_at');

            $arrivalCarbon = $plannedArrival !== null && $plannedArrival !== '' ? Carbon::parse($plannedArrival, $timezone) : null;
            $joinCarbon = $plannedJoin !== null && $plannedJoin !== '' ? Carbon::parse($plannedJoin, $timezone) : null;
            $signoffCarbon = $plannedSignoff !== null && $plannedSignoff !== '' ? Carbon::parse($plannedSignoff, $timezone) : null;

            if ($arrivalCarbon !== null && $joinCarbon !== null && $arrivalCarbon->toDateString() > $joinCarbon->toDateString()) {
                $validator->errors()->add('planned_arrival_at', 'Arrival Date cannot be after Expected Vessel Join.');
            }

            if ($joinCarbon !== null && $signoffCarbon !== null && $signoffCarbon->toDateString() < $joinCarbon->toDateString()) {
                $validator->errors()->add('planned_signoff_at', 'Expected Sign-off cannot be before Expected Vessel Join.');
            }

            $conflictEvaluator = new CrewAssignmentConflictEvaluator;
            $intent = $this->submissionIntent();
            $operationalStartAt = $intent === CrewAssignmentSubmissionIntent::Start
                ? Carbon::now($timezone)
                : null;

            $conflictContext = new CrewAssignmentConflictContext(
                companyId: $companyId,
                employeeId: (int) $this->input('employee_id'),
                action: $intent->value,
                plannedJoinAt: $joinCarbon,
                plannedSignoffAt: $signoffCarbon,
                plannedArrivalAt: $arrivalCarbon,
                operationalStartAt: $operationalStartAt,
                vesselId: $vesselId !== null && $vesselId !== '' ? (int) $vesselId : null,
                positionId: $this->conflictPositionId($companyId),
                clientId: $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                relievesCrewAssignmentId: $this->input('relieves_crew_assignment_id') !== null && $this->input('relieves_crew_assignment_id') !== ''
                    ? (int) $this->input('relieves_crew_assignment_id')
                    : null,
                actor: $this->user(),
            );

            $result = $conflictEvaluator->evaluate($conflictContext);
            if ($result->blocking) {
                $validator->errors()->add('employee_id', $result->message);
                $validator->errors()->add('conflict', json_encode($result->toArray()));
            }
        });
    }

    public function submissionIntent(): CrewAssignmentSubmissionIntent
    {
        return CrewAssignmentSubmissionIntent::tryFrom((string) $this->input('submission_intent'))
            ?? CrewAssignmentSubmissionIntent::Draft;
    }

    private function conflictPositionId(int $companyId): ?int
    {
        $positionId = $this->input('position_id');

        return $positionId !== null && $positionId !== '' ? (int) $positionId : null;
    }
}
