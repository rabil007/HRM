<?php

namespace App\Support\Payroll;

use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use Illuminate\Support\Collection;

final class PayrollPeriodNeedsUpdate
{
    /**
     * @return array{needs_update: bool, reasons: list<string>}
     */
    public function forPeriod(PayrollPeriod $period): array
    {
        if (! $period->canGeneratePayroll()) {
            return [
                'needs_update' => false,
                'reasons' => [],
            ];
        }

        if (! PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->exists()) {
            return [
                'needs_update' => false,
                'reasons' => [],
            ];
        }

        $reasons = [];

        if ($this->salaryInputsChanged($period)) {
            $reasons[] = 'salary_inputs';
        }

        if ($period->isCrew()) {
            if ($this->timesheetsChanged($period)) {
                $reasons[] = 'timesheets';
            }

            if ($this->hasPendingTimesheets($period)) {
                $reasons[] = 'new_timesheets';
            }
        }

        return [
            'needs_update' => $reasons !== [],
            'reasons' => $reasons,
        ];
    }

    private function salaryInputsChanged(PayrollPeriod $period): bool
    {
        $records = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->get(['id', 'employee_id', 'calculation_breakdown']);

        /** @var Collection<int, Collection<int, SalaryInput>> $inputsByEmployee */
        $inputsByEmployee = SalaryInput::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $recordEmployeeIds = $records->pluck('employee_id')->flip();

        foreach ($inputsByEmployee->keys() as $employeeId) {
            if (! $recordEmployeeIds->has($employeeId)) {
                return true;
            }
        }

        foreach ($records as $record) {
            /** @var PayrollRecord $record */
            $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
            $storedFingerprint = $this->fingerprintFromBreakdown($breakdown);
            $currentFingerprint = $this->fingerprintFromInputs(
                $inputsByEmployee->get($record->employee_id, Collection::make()),
            );

            if ($storedFingerprint !== $currentFingerprint) {
                return true;
            }
        }

        return false;
    }

    private function timesheetsChanged(PayrollPeriod $period): bool
    {
        $records = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->get(['employee_id', 'updated_at', 'calculation_breakdown']);

        $timesheets = CrewTimesheet::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->get()
            ->keyBy('employee_id');

        foreach ($records as $record) {
            /** @var PayrollRecord $record */
            $timesheet = $timesheets->get($record->employee_id);

            if ($timesheet === null) {
                continue;
            }

            if ($timesheet->updated_at !== null
                && $record->updated_at !== null
                && $timesheet->updated_at->gt($record->updated_at)) {
                return true;
            }

            $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];

            if ((float) ($breakdown['standby_days'] ?? -1) !== (float) ($timesheet->standby_days ?? 0)) {
                return true;
            }

            if ((float) ($breakdown['onsite_days'] ?? -1) !== (float) ($timesheet->onsite_days ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private function hasPendingTimesheets(PayrollPeriod $period): bool
    {
        $excludedEmployeeIds = array_values(array_unique(array_map(
            intval(...),
            $period->excluded_employee_ids ?? [],
        )));

        $recordEmployeeIds = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return CrewTimesheet::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->when($excludedEmployeeIds !== [], fn ($query) => $query->whereNotIn('employee_id', $excludedEmployeeIds))
            ->when($recordEmployeeIds !== [], fn ($query) => $query->whereNotIn('employee_id', $recordEmployeeIds))
            ->exists();
    }

    /**
     * @param  Collection<int, SalaryInput>  $inputs
     */
    private function fingerprintFromInputs(Collection $inputs): string
    {
        if ($inputs->isEmpty()) {
            return '';
        }

        return $inputs
            ->sortBy('id')
            ->map(fn (SalaryInput $input) => implode(':', [
                $input->id,
                $input->salary_input_type_id,
                number_format((float) $input->amount, 2, '.', ''),
            ]))
            ->implode('|');
    }

    /**
     * @param  array<string, mixed>  $breakdown
     */
    private function fingerprintFromBreakdown(array $breakdown): string
    {
        $rows = is_array($breakdown['salary_inputs'] ?? null) ? $breakdown['salary_inputs'] : [];

        if ($rows === []) {
            return '';
        }

        return collect($rows)
            ->sortBy('id')
            ->map(fn (array $row) => implode(':', [
                $row['id'],
                $row['salary_input_type_id'],
                $row['amount'],
            ]))
            ->implode('|');
    }
}
