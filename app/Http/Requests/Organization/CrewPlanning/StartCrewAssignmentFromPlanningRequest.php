<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'planned_arrival_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $plannedArrival = $this->input('planned_arrival_at');
            if ($plannedArrival === null || $plannedArrival === '') {
                return;
            }

            /** @var CrewPlanningAssignment|null $planning */
            $planning = $this->route('assignment');
            if ($planning === null || $planning->planned_join_date === null) {
                return;
            }

            $arrivalDate = Carbon::parse((string) $plannedArrival)->toDateString();
            $joinDate = $planning->planned_join_date->toDateString();

            if ($arrivalDate > $joinDate) {
                $validator->errors()->add('planned_arrival_at', 'Arrival Date cannot be after Expected Vessel Join.');
            }
        });
    }
}
