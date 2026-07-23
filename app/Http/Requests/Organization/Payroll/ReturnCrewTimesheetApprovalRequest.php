<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ReturnCrewTimesheetApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.crew_timesheets.return');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'return_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
