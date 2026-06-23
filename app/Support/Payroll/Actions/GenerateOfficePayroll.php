<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\CountWorkingDaysInRange;
use App\Support\Payroll\GeneratePayrollResult;
use App\Support\Payroll\OfficeAttendanceSummary;
use App\Support\Payroll\OfficePayrollCalculator;
use App\Support\Payroll\PayrollEmployeeQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateOfficePayroll
{
    public function __construct(
        private readonly OfficePayrollCalculator $calculator,
        private readonly CountWorkingDaysInRange $countWorkingDays,
    ) {}

    public function handle(PayrollPeriod $period): GeneratePayrollResult
    {
        abort_unless($period->isOffice(), 404);

        if (! $period->canGenerateOfficePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Office payroll can only be generated for draft or processing periods.',
            ]);
        }

        $companyWorkingDays = $this->resolveCompanyWorkingDays($period->company_id);
        $workingDaysInPeriod = $this->countWorkingDays->count(
            $period->start_date,
            $period->end_date,
            $companyWorkingDays,
        );

        $employees = PayrollEmployeeQuery::activeQuery($period->company_id, PayrollCategory::Office)
            ->with(['currentContract.salaryComponents'])
            ->orderBy('employees.name')
            ->get();

        $attendanceByEmployee = $this->loadAttendanceByEmployee(
            $period->company_id,
            $period->start_date->toDateString(),
            $period->end_date->toDateString(),
            $employees->pluck('id')->all(),
        );

        $generatedCount = 0;
        $skippedEmployees = [];
        $errors = [];

        DB::transaction(function () use (
            $period,
            $employees,
            $attendanceByEmployee,
            $companyWorkingDays,
            $workingDaysInPeriod,
            &$generatedCount,
            &$skippedEmployees,
            &$errors,
        ): void {
            foreach ($employees as $employee) {
                /** @var Employee $employee */
                /** @var Collection<int, AttendanceRecord> $records */
                $records = $attendanceByEmployee->get($employee->id, Collection::make());

                if ($records->isEmpty()) {
                    $skippedEmployees[] = [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employee_no' => $employee->employee_no,
                    ];

                    continue;
                }

                $contract = $employee->currentContract;

                if ($contract === null) {
                    $errors[] = [
                        'employee_id' => $employee->id,
                        'message' => 'No active office contract found.',
                    ];

                    continue;
                }

                $summary = OfficeAttendanceSummary::fromRecords(
                    $records,
                    $workingDaysInPeriod,
                    $companyWorkingDays,
                );

                try {
                    $calculated = $this->calculator->calculate(
                        $summary,
                        $contract->salaryComponents,
                        $workingDaysInPeriod,
                    );
                } catch (ValidationException $exception) {
                    $errors[] = [
                        'employee_id' => $employee->id,
                        'message' => collect($exception->errors())->flatten()->first() ?? 'Calculation failed.',
                    ];

                    continue;
                }

                PayrollRecord::query()->updateOrCreate(
                    [
                        'company_id' => $period->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $period->id,
                    ],
                    [
                        'payroll_category' => PayrollCategory::Office,
                        'basic_salary' => $calculated['basic_salary'],
                        'housing_allowance' => $calculated['housing_allowance'],
                        'transport_allowance' => $calculated['transport_allowance'],
                        'other_allowances' => $calculated['other_allowances'],
                        'overtime_pay' => $calculated['overtime_pay'],
                        'bonus' => $calculated['bonus'],
                        'unpaid_leave_deduction' => $calculated['unpaid_leave_deduction'],
                        'late_deduction' => $calculated['late_deduction'],
                        'loan_deduction' => $calculated['loan_deduction'],
                        'other_deductions' => $calculated['other_deductions'],
                        'total_deductions' => $calculated['total_deductions'],
                        'gross_salary' => $calculated['gross_salary'],
                        'net_salary' => $calculated['net_salary'],
                        'working_days' => $calculated['working_days'],
                        'present_days' => (int) round($calculated['present_days']),
                        'absent_days' => (int) round($calculated['absent_days']),
                        'overtime_hours' => $calculated['overtime_hours'],
                        'calculation_breakdown' => $calculated['calculation_breakdown'],
                        'status' => 'draft',
                    ],
                );

                $generatedCount++;
            }

            if ($generatedCount > 0 && $period->status === PayrollPeriodStatus::Draft) {
                $period->update([
                    'status' => PayrollPeriodStatus::Processing,
                ]);
            }
        });

        return new GeneratePayrollResult(
            generatedCount: $generatedCount,
            skippedCount: count($skippedEmployees),
            skippedEmployees: $skippedEmployees,
            errors: $errors,
        );
    }

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, Collection<int, AttendanceRecord>>
     */
    private function loadAttendanceByEmployee(
        int $companyId,
        string $startDate,
        string $endDate,
        array $employeeIds,
    ): Collection {
        if ($employeeIds === []) {
            return Collection::make();
        }

        return AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->orderBy('date')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * @return list<int>
     */
    private function resolveCompanyWorkingDays(int $companyId): array
    {
        $workingDays = Company::query()
            ->whereKey($companyId)
            ->value('working_days');

        if (! is_array($workingDays) || $workingDays === []) {
            return [1, 2, 3, 4, 5];
        }

        return array_map(intval(...), $workingDays);
    }
}
