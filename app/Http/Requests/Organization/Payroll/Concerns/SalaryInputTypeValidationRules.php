<?php

namespace App\Http\Requests\Organization\Payroll\Concerns;

use App\Models\SalaryInputType;
use Illuminate\Validation\Rule;

trait SalaryInputTypeValidationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function salaryInputTypeRules(?SalaryInputType $salaryInputType = null): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('salary_input_types', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($salaryInputType?->id),
            ],
            'is_addition' => ['required', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
