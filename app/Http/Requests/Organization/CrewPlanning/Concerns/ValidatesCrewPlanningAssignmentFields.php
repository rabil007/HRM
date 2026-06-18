<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesCrewPlanningAssignmentFields
{
    /**
     * @return array<string, mixed>
     */
    protected function crewPlanningEmployeeIdRule(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->whereNotNull('rank_id')),
        ];
    }
}
