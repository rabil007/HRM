<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use App\Http\Requests\Organization\CrewPlanning\Concerns\ValidatesCrewPlanningAssignmentFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrewPlanningAssignmentRequest extends FormRequest
{
    use ValidatesCrewPlanningAssignmentFields;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')],
            'rank_id' => ['required', 'integer', Rule::exists('ranks', 'id')],
            'employee_id' => $this->crewPlanningEmployeeIdRule(),
            'planned_join_date' => ['required', 'date'],
            'planned_leave_date' => ['required', 'date', 'after_or_equal:planned_join_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
