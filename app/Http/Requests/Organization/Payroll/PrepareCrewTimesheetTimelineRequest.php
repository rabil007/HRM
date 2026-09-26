<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Foundation\Http\FormRequest;

class PrepareCrewTimesheetTimelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('payroll.crew_timesheets.prepare')) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        return EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId);
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
