<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\CrewPayrollCalculator;
use App\Support\Payroll\GeneratePayrollResult;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollGenerationError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateCrewPayroll
{
    public function __construct(
        private readonly CrewPayrollCalculator $calculator,
    ) {}

    public function handle(PayrollPeriod $period): GeneratePayrollResult
    {
        abort_unless($period->isCrew(), 404);

        if (! $period->canGenerateCrewPayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Crew payroll can only be generated for draft or processing periods.',
            ]);
        }

        $employees = PayrollEmployeeQuery::activeQuery($period->company_id, PayrollCategory::Crew)
            ->with([
                'currentContract.salaryComponents',
                'crewTimesheets' => fn ($query) => $query->where('period_id', $period->id),
            ])
            ->orderBy('employees.name')
            ->get();

        $excludedEmployeeIds = array_values(array_unique(array_map(
            intval(...),
            $period->excluded_employee_ids ?? [],
        )));

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
                if (in_array((int) $employee->id, $excludedEmployeeIds, true)) {
                    continue;
                }
                /** @var Employee $employee */
                $timesheet = $employee->crewTimesheets->first();

                if ($timesheet === null) {
                    $skippedEmployees[] = [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employee_no' => $employee->employee_no,
                    ];

                    continue;
                }

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
                    );
                } catch (ValidationException $exception) {
                    $errors[] = PayrollGenerationError::fromValidationException($employee, $exception);

                    continue;
                }

                PayrollRecord::query()->updateOrCreate(
                    [
                        'company_id' => $period->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $period->id,
                    ],
                    [
                        'payroll_category' => PayrollCategory::Crew,
                        'basic_salary' => $calculated['basic_salary'],
                        'other_allowances' => $calculated['other_allowances'],
                        'overtime_pay' => $calculated['overtime_pay'],
                        'bonus' => $calculated['bonus'],
                        'other_deductions' => $calculated['other_deductions'],
                        'total_deductions' => $calculated['total_deductions'],
                        'gross_salary' => $calculated['gross_salary'],
                        'net_salary' => $calculated['net_salary'],
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
}
