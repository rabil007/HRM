<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\CrewTimesheetMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayrollPeriodCrewTimesheetModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.periods.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'crew_timesheet_mode' => ['required', Rule::in(CrewTimesheetMode::values())],
        ];
    }
}
