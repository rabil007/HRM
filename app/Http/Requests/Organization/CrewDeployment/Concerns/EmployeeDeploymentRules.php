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
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')->where('is_active', true)],
            'arrived_date' => ['nullable', 'date'],
            'join_standby_from' => ['nullable', 'date'],
            'join_standby_to' => ['nullable', 'date', 'after_or_equal:join_standby_from'],
            'leave_standby_from' => ['nullable', 'date'],
            'leave_standby_to' => ['nullable', 'date', 'after_or_equal:leave_standby_from'],
            'joined_date' => ['nullable', 'date'],
            'disembarked_date' => ['nullable', 'date', 'after_or_equal:joined_date'],
            'travelled_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'redirect_to' => ['nullable', 'string', 'in:show'],
        ];
    }
}
