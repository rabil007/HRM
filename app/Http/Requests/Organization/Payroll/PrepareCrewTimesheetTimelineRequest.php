<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class PrepareCrewTimesheetTimelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.crew_timesheets.prepare');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cutoff_date' => ['nullable', 'date'],
        ];
    }
}
