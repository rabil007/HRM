<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use App\Http\Requests\Organization\Concerns\TranslatesLegacyCrewRankToPosition;
use App\Http\Requests\Organization\CrewPlanning\Concerns\ValidatesCrewPlanningAssignmentFields;
use App\Support\Positions\RankPositionBridge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewPlanningAssignmentRequest extends FormRequest
{
    use TranslatesLegacyCrewRankToPosition;
    use ValidatesCrewPlanningAssignmentFields;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($companyId > 0) {
            $this->mergeLegacyCrewPositionFromRank($companyId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'vessel_id' => [
                'sometimes',
                'integer',
                Rule::exists('vessels', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'position_id' => ['sometimes', 'integer', RankPositionBridge::existsCrewPositionRule($companyId)],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')],
            'employee_id' => $this->crewPlanningEmployeeIdMustBeAbsentRule(),
            'planned_join_date' => ['sometimes', 'date'],
            'planned_leave_date' => ['sometimes', 'date', 'after_or_equal:planned_join_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'relieves_crew_assignment_id' => $this->crewPlanningRelievesAssignmentIdRule(),
        ];
    }
}
