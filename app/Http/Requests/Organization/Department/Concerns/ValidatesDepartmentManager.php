<?php

namespace App\Http\Requests\Organization\Department\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesDepartmentManager
{
    protected function prepareForValidation(): void
    {
        if (filled($this->input('parent_id'))) {
            $this->merge(['manager_id' => null]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function managerIdRules(int $companyId): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            Rule::prohibitedIf(fn () => filled($this->input('parent_id'))),
        ];
    }
}
