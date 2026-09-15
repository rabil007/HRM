<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use App\Enums\CrewPhaseCode;
use App\Models\CrewPlanningAssignment;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartCrewAssignmentFromPlanningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('crew_operations.assignments.create')
            || ! $user->can('crew_operations.movements.perform')) {
            return false;
        }

        /** @var CrewPlanningAssignment|null $planning */
        $planning = $this->route('assignment');
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($planning !== null && (int) $planning->company_id !== $companyId) {
            abort(404);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $vesselId = $this->input('vessel_id');
        $clientId = $this->input('client_id');
        $merge = [];

        if ($this->input('current_stage') === null || $this->input('current_stage') === '') {
            $merge['current_stage'] = CrewPhaseCode::TravelIn->value;
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

        return [
            'employee_id' => [
                'required',
                'integer',
                ActiveCompanyEmployeeRule::exists($companyId),
            ],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'planned_join_at' => ['nullable', 'date'],
            'current_stage' => [
                'required',
                'string',
                Rule::in(array_map(
                    fn (CrewPhaseCode $phase): string => $phase->value,
                    CrewPhaseCode::directStartPhases(),
                )),
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_stage.in' => 'Assignments cannot start directly in this stage. Start at Travel In or Pre-Mobilisation so payable join-standby history is recorded.',
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
        });
    }
}
