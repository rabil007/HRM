<?php

namespace App\Http\Requests\Organization\CrewDeployment\Concerns;

use Illuminate\Validation\Rule;

trait EmployeeDeploymentRules
{
    /**
     * @return array<string, mixed>
     */
    protected function deploymentFieldRules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'arrived_date' => ['nullable', 'date'],
            'standby_from' => ['nullable', 'date'],
            'standby_to' => ['nullable', 'date', 'after_or_equal:standby_from'],
            'joined_date' => ['nullable', 'date'],
            'disembarked_date' => ['nullable', 'date', 'after_or_equal:joined_date'],
            'travelled_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
