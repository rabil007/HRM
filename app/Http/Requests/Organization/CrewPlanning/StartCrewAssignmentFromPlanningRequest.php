<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use App\Enums\CrewPhaseCode;
use App\Models\CrewPlanningAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCrewAssignmentFromPlanningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (! $user->can('crew_operations.planning.view')
            || ! $user->can('crew_operations.assignments.create')
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
        if ($this->input('current_stage') === null || $this->input('current_stage') === '') {
            $this->merge([
                'current_stage' => CrewPhaseCode::TravelIn->value,
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
}
