<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use App\Http\Requests\Organization\CrewPlanning\Concerns\ValidatesCrewPlanningAssignmentFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewPlanningAssignmentRequest extends FormRequest
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
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'vessel_id' => ['sometimes', 'integer', Rule::exists('vessels', 'id')->where('company_id', $companyId)],
            'rank_id' => ['sometimes', 'integer', Rule::exists('ranks', 'id')],
            'employee_id' => $this->crewPlanningEmployeeIdRule(),
            'planned_join_date' => ['sometimes', 'date'],
            'planned_leave_date' => ['sometimes', 'date', 'after_or_equal:planned_join_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'relieves_crew_assignment_id' => $this->crewPlanningRelievesAssignmentIdRule(),
        ];
    }
}
