<?php

namespace App\Http\Requests\Organization\Employee\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesEmployeeNumber
{
    /**
     * @return list<mixed>
     */
    protected function employeeNumberRules(int $companyId, ?int $ignoreEmployeeId = null): array
    {
        $rule = Rule::unique('employees', 'employee_no')
            ->where(fn ($query) => $query->where('company_id', $companyId));

        if ($ignoreEmployeeId !== null) {
            $rule->ignore($ignoreEmployeeId);
        }

        return [
            'required',
            'string',
            'max:50',
            $rule,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_no.unique' => 'This employee number is already used in your company. Choose a different number.',
        ];
    }
}
