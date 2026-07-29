<?php

namespace App\Http\Requests\Organization\Department\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesDepartmentManager
{
    /**
     * @return array<int, mixed>
     */
    protected function managerIdRules(int $companyId): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')->where(fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->whereNull('deleted_at')),
        ];
    }
}
