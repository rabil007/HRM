<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\CrewOvertimeMonthlySalary;
use App\Support\Payroll\CrewPayrollCalculator;
use App\Support\Payroll\GeneratePayrollResult;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollGenerationError;
use App\Support\Payroll\ResolvePayrollRecordSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateCrewPayroll
{
    public function __construct(
        private readonly CrewPayrollCalculator $calculator,
        private readonly RecalculateCrewPayroll $recalculateCrewPayroll,
    ) {}

    public function handle(PayrollPeriod $period, array $excludedEmployeeIds = []): GeneratePayrollResult
    {
        abort_unless($period->isCrew(), 404);

        if (! $period->canGenerateCrewPayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Crew payroll can only be generated for draft or processing periods.',
            ]);
        }

        $excludedEmployeeIds = array_values(array_unique(array_map(
            intval(...),
            array_merge($period->excluded_employee_ids ?? [], $excludedEmployeeIds),
        )));

        $employeesQuery = PayrollEmployeeQuery::activeQuery($period->company_id, PayrollCategory::Crew);

        if ($excludedEmployeeIds !== []) {
            $employeesQuery->whereNotIn('employees.id', $excludedEmployeeIds);
        }

        $employees = $employeesQuery
            ->with([
                'currentContract.salaryComponents',
                'primaryBankAccount',
                'crewTimesheets' => fn ($query) => $query->where('period_id', $period->id),
            ])
            ->orderBy('employees.name')
            ->get();

        $generatedCount = 0;
        $skippedEmployees = [];
        $errors = [];

        DB::transaction(function () use ($period, $employees, $excludedEmployeeIds, &$generatedCount, &$skippedEmployees, &$errors): void {
            if ($excludedEmployeeIds !== []) {
                PayrollRecord::query()
                    ->where('period_id', $period->id)
                    ->whereIn('employee_id', $excludedEmployeeIds)
                    ->delete();
            }

            foreach ($employees as $employee) {
                /** @var Employee $employee */
                $timesheet = $employee->crewTimesheets->first()
                    ?? new CrewTimesheet([
                        'standby_days' => 0,
                        'onsite_days' => 0,
                        'overtime_hours' => 0,
                        'additional_amount' => 0,
                        'deduction_amount' => 0,
                    ]);

                $contract = $employee->currentContract;

                if ($contract === null) {
                    $errors[] = PayrollGenerationError::forEmployee(
                        $employee,
                        'No active crew contract found.',
                        'contract',
                    );

                    continue;
                }

                try {
                    $calculated = $this->calculator->calculate(
                        $timesheet,
                        $contract->salaryComponents,
                        CrewOvertimeMonthlySalary::STANDARD_PERIOD_DAYS,
                        $period->calendarDayCount(),
                    );
                } catch (ValidationException $exception) {
                    $errors[] = PayrollGenerationError::fromValidationException($employee, $exception);

                    continue;
                }

                $breakdown = $calculated['calculation_breakdown'];
                $breakdown['base'] = [
                    'gross' => (float) $calculated['gross_salary'],
                    'net' => (float) $calculated['net_salary'],
                    'bonus' => (float) $calculated['bonus'],
                    'other_deductions' => (float) $calculated['other_deductions'],
                ];

                PayrollRecord::query()->updateOrCreate(
                    [
                        'company_id' => $period->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $period->id,
                    ],
                    [
                        ...ResolvePayrollRecordSnapshot::from($employee, $contract),
                        'payroll_category' => PayrollCategory::Crew,
                        'salary_payment_method' => $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer,
                        'basic_salary' => $calculated['basic_salary'],
                        'other_allowances' => $calculated['other_allowances'],
                        'overtime_pay' => $calculated['overtime_pay'],
                        'bonus' => $calculated['bonus'],
                        'other_deductions' => $calculated['other_deductions'],
                        'total_deductions' => $calculated['total_deductions'],
                        'gross_salary' => $calculated['gross_salary'],
                        'net_salary' => $calculated['net_salary'],
                        'working_days' => $calculated['working_days'],
                        'present_days' => (int) round($calculated['present_days']),
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
                $this->recalculateCrewPayroll->handle($period->fresh());
            }
        });

        return new GeneratePayrollResult(
            generatedCount: $generatedCount,
            skippedCount: count($skippedEmployees),
            skippedEmployees: $skippedEmployees,
            errors: $errors,
        );
    }
}
