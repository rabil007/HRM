<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Validation\ValidationException;

final class UpsertCrewTimesheet
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PayrollPeriod $period, Employee $employee, array $data): CrewTimesheet
    {
        abort_unless((int) $period->company_id === (int) $employee->company_id, 404);

        if (! $period->isEditable()) {
            throw ValidationException::withMessages([
                'period_id' => 'Timesheets can only be edited for draft payroll periods.',
            ]);
        }

        $employee->loadMissing('currentContract');

        if ($employee->currentContract?->payroll_category !== PayrollCategory::Crew) {
            throw ValidationException::withMessages([
                'employee_id' => 'Only employees with an active crew contract can have crew timesheets.',
            ]);
        }

        return CrewTimesheet::query()->updateOrCreate(
            [
                'company_id' => $period->company_id,
                'employee_id' => $employee->id,
                'period_id' => $period->id,
            ],
            [
                'standby_from' => $data['standby_from'] ?? null,
                'standby_to' => $data['standby_to'] ?? null,
                'standby_days' => $data['standby_days'] ?? null,
                'onsite_from' => $data['onsite_from'] ?? null,
                'onsite_to' => $data['onsite_to'] ?? null,
                'onsite_days' => $data['onsite_days'] ?? null,
                'overtime_amount' => $data['overtime_amount'] ?? 0,
                'additional_amount' => $data['additional_amount'] ?? 0,
                'deduction_amount' => $data['deduction_amount'] ?? 0,
                'remarks' => $data['remarks'] ?? null,
            ],
        );
    }
}
