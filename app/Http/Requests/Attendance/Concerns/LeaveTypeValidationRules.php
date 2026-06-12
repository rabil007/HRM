<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\LeaveType;
use Illuminate\Validation\Rule;

trait LeaveTypeValidationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function leaveTypeRules(?LeaveType $leaveType = null): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('leave_types', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($leaveType?->id),
            ],
            'days_per_year' => ['required', 'numeric', 'min:0'],
            'carry_forward' => ['sometimes', 'boolean'],
            'max_carry_days' => ['required', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
