<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\CountWorkingDaysInRange;
use App\Support\Payroll\GeneratePayrollResult;
use App\Support\Payroll\OfficeLeavePeriodSummary;
use App\Support\Payroll\OfficePayrollCalculator;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollGenerationError;
use App\Support\Payroll\ResolvePayrollRecordSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateOfficePayroll
{
    public function __construct(
        private readonly OfficePayrollCalculator $calculator,
        private readonly CountWorkingDaysInRange $countWorkingDays,
        private readonly OfficeLeavePeriodSummary $leavePeriodSummary,
        private readonly RecalculateOfficePayroll $recalculateOfficePayroll,
    ) {}

    public function handle(PayrollPeriod $period, array $excludedEmployeeIds = [], array $employeeDates = []): GeneratePayrollResult
    {
        abort_unless($period->isOffice(), 404);

        if (! $period->canGenerateOfficePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Office payroll can only be generated for draft or processing periods.',
            ]);
        }

        $excludedEmployeeIds = array_values(array_unique(array_map(
            intval(...),
            array_merge($period->excluded_employee_ids ?? [], $excludedEmployeeIds),
        )));

        $workingDaysInPeriod = (int) $period->start_date->diffInDays($period->end_date) + 1;

        $employeesQuery = PayrollEmployeeQuery::activeQuery($period->company_id, PayrollCategory::Office);

        if (! empty($excludedEmployeeIds)) {
            $employeesQuery->whereNotIn('employees.id', $excludedEmployeeIds);
        }

        $employees = $employeesQuery->with(['currentContract.salaryComponents', 'primaryBankAccount'])
            ->orderBy('employees.name')
            ->get();

        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
        $leaveByEmployee = $this->leavePeriodSummary->forEmployees(
            $period->company_id,
            $period->start_date->toDateString(),
            $period->end_date->toDateString(),
            $employeeIds,
        );

        $generatedCount = 0;
        $errors = [];

        DB::transaction(function () use (
            $period,
            $employees,
            $leaveByEmployee,
            $workingDaysInPeriod,
            $excludedEmployeeIds,
            $employeeDates,
            &$generatedCount,
            &$errors,
        ): void {
            if (! empty($excludedEmployeeIds)) {
                PayrollRecord::query()
                    ->where('period_id', $period->id)
                    ->whereIn('employee_id', $excludedEmployeeIds)
                    ->forceDelete();
            }
            foreach ($employees as $employee) {
                /** @var Employee $employee */
                $contract = $employee->currentContract;

                if ($contract === null) {
                    $errors[] = PayrollGenerationError::forEmployee(
                        $employee,
                        'No active office contract found.',
                        'contract',
                    );

                    continue;
                }

                $leaveSummary = $leaveByEmployee->get(
                    $employee->id,
                    $this->leavePeriodSummary->empty($period->company_id),
                );

                $employeeLeaveDays = (float) $leaveSummary->totalLeaveDays;
                $unworkedDays = 0.0;
                $startDate = $period->start_date;
                $endDate = $period->end_date;

                if (isset($employeeDates[$employee->id]) || isset($employeeDates[(string) $employee->id])) {
                    /** @var array{start_date?: string, end_date?: string} $dates */
                    $dates = $employeeDates[$employee->id] ?? $employeeDates[(string) $employee->id];
                    $startDate = ! empty($dates['start_date']) ? Carbon::parse($dates['start_date']) : $period->start_date;
                    $endDate = ! empty($dates['end_date']) ? Carbon::parse($dates['end_date']) : $period->end_date;
                    $activeDays = (int) $startDate->diffInDays($endDate) + 1;
                    $unworkedDays = (float) max(0, $workingDaysInPeriod - $activeDays);
                    $employeeLeaveDays += $unworkedDays;
                }

                try {
                    $calculated = $this->calculator->calculate(
                        $contract->salaryComponents,
                        $workingDaysInPeriod,
                        $employeeLeaveDays,
                        $leaveSummary->toLeaveUsageArray(),
                        $unworkedDays,
                    );
                } catch (ValidationException $exception) {
                    $errors[] = PayrollGenerationError::fromValidationException($employee, $exception);

                    continue;
                }

                $breakdown = $calculated['calculation_breakdown'];
                $breakdown['period_start_date'] = $startDate->toDateString();
                $breakdown['period_end_date'] = $endDate->toDateString();

                PayrollRecord::query()->updateOrCreate(
                    [
                        'company_id' => $period->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $period->id,
                    ],
                    [
                        ...ResolvePayrollRecordSnapshot::from($employee, $contract),
                        'payroll_category' => PayrollCategory::Office,
                        'salary_payment_method' => $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer,
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
                        'leave_days' => $calculated['leave_days'],
                        'overtime_hours' => $calculated['overtime_hours'],
                        'calculation_breakdown' => $breakdown,
                        'status' => 'draft',
                    ],
                );

                $generatedCount++;
            }

            $periodUpdates = [
                'excluded_employee_ids' => $excludedEmployeeIds,
            ];

            if ($generatedCount > 0 && $period->status === PayrollPeriodStatus::Draft) {
                $periodUpdates['status'] = PayrollPeriodStatus::Processing;
            }

            $period->update($periodUpdates);

            if ($generatedCount > 0) {
                $this->recalculateOfficePayroll->handle($period->fresh());
            }
        });

        return new GeneratePayrollResult(
            generatedCount: $generatedCount,
            skippedCount: 0,
            skippedEmployees: [],
            errors: $errors,
        );
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
