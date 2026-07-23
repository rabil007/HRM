<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ApproveCrewTimesheetApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.crew_timesheets.approve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
