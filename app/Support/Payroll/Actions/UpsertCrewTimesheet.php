<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetSource;
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

        if (! $period->isCrew()) {
            throw ValidationException::withMessages([
                'period_id' => 'Crew timesheets can only be saved on crew pay periods.',
            ]);
        }

        $employee->loadMissing('currentContract');

        if ($employee->currentContract?->payroll_category !== PayrollCategory::Crew) {
            throw ValidationException::withMessages([
                'employee_id' => 'Only employees with an active crew contract can have crew timesheets.',
            ]);
        }

        $existing = CrewTimesheet::query()
            ->where('company_id', $period->company_id)
            ->where('employee_id', $employee->id)
            ->where('period_id', $period->id)
            ->with('preparation')
            ->first();

        $source = $this->resolveSource($data, $existing);

        if ($existing !== null && $existing->isOperationallyLocked()) {
            $existing->fill([
                'overtime_hours' => $data['overtime_hours'] ?? $existing->overtime_hours ?? 0,
                'additional_amount' => $data['additional_amount'] ?? $existing->additional_amount ?? 0,
                'deduction_amount' => $data['deduction_amount'] ?? $existing->deduction_amount ?? 0,
                'remarks' => array_key_exists('remarks', $data)
                    ? $data['remarks']
                    : $existing->remarks,
            ]);
            $existing->save();

            return $existing->fresh() ?? $existing;
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
                'overtime_hours' => $data['overtime_hours'] ?? 0,
                'additional_amount' => $data['additional_amount'] ?? 0,
                'deduction_amount' => $data['deduction_amount'] ?? 0,
                'remarks' => $data['remarks'] ?? null,
                'source' => $source,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSource(array $data, ?CrewTimesheet $existing): CrewTimesheetSource
    {
        if ($existing !== null && $existing->isOperationallyLocked()) {
            return CrewTimesheetSource::CrewOperations;
        }

        if (($data['source'] ?? null) instanceof CrewTimesheetSource) {
            return $data['source'];
        }

        if (is_string($data['source'] ?? null)) {
            return CrewTimesheetSource::tryFrom($data['source']) ?? CrewTimesheetSource::Manual;
        }

        return CrewTimesheetSource::Manual;
    }
}
