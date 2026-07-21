<?php

namespace App\Support\Payroll\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\CrewMonthlyPayrollCalculator;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewOvertimeMonthlySalary;
use App\Support\Payroll\CrewPayrollCalculator;
use App\Support\Payroll\GeneratePayrollResult;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollGenerationError;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Payroll\ResolveEffectiveContractSalaryComponents;
use App\Support\Payroll\ResolvePayrollRecordSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateCrewPayroll
{
    public function __construct(
        private readonly CrewPayrollCalculator $calculator,
        private readonly CrewMonthlyPayrollCalculator $monthlyCalculator,
        private readonly RecalculateCrewPayroll $recalculateCrewPayroll,
        private readonly ResolveEffectiveContractSalaryComponents $resolveEffectiveComponents,
        private readonly CrewOperationsPayrollGenerationGuard $crewOperationsGuard,
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
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
                'primaryBankAccount',
                'crewTimesheets' => fn ($query) => $query
                    ->where('period_id', $period->id)
                    ->with('preparation'),
            ])
            ->orderBy('employees.name')
            ->get();

        $resolvedContracts = $this->resolveContract->resolveMany(
            $period,
            $employees->pluck('id')->map(intval(...))->all(),
            ['salaryComponents', 'salaryRevisions.lines'],
        );

        $generatedCount = 0;
        $skippedEmployees = [];
        $errors = [];
        $workingDaysInPeriod = $period->calendarDayCount();

        DB::transaction(function () use (
            $period,
            $employees,
            $resolvedContracts,
            $excludedEmployeeIds,
            $workingDaysInPeriod,
            &$generatedCount,
            &$skippedEmployees,
            &$errors,
        ): void {
            $lockedPeriod = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $period->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedPeriod->isCrew(), 404);

            if (! $lockedPeriod->canGenerateCrewPayroll()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Crew payroll can only be generated for draft or processing periods.',
                ]);
            }

            $usesCrewOperations = $lockedPeriod->crew_timesheet_mode === CrewTimesheetMode::CrewOperations;

            if ($usesCrewOperations) {
                $this->crewOperationsGuard->assertReadyForGeneration(
                    $lockedPeriod,
                    $employees,
                    (int) $lockedPeriod->company_id,
                );
            }

            if ($excludedEmployeeIds !== []) {
                PayrollRecord::query()
                    ->where('period_id', $lockedPeriod->id)
                    ->whereIn('employee_id', $excludedEmployeeIds)
                    ->forceDelete();
            }

            foreach ($employees as $employee) {
                /** @var Employee $employee */
                $contract = $resolvedContracts->get((int) $employee->id);

                if ($contract === null) {
                    $errors[] = PayrollGenerationError::forEmployee(
                        $employee,
                        'No active crew contract found.',
                        'contract',
                    );

                    continue;
                }

                $salaryStructure = $contract->resolvedSalaryStructure();
                $timesheet = $employee->crewTimesheets->first();

                if ($timesheet === null) {
                    if ($usesCrewOperations && $salaryStructure === ContractSalaryStructure::Daily) {
                        continue;
                    }

                    $timesheet = new CrewTimesheet([
                        'sign_on_standby_days' => 0,
                        'onsite_days' => 0,
                        'sign_off_standby_days' => 0,
                        'unpaid_leave_days' => 0,
                        'overtime_hours' => 0,
                        'additional_amount' => 0,
                        'deduction_amount' => 0,
                        'source' => CrewTimesheetSource::Manual,
                    ]);
                }

                try {
                    $recordAttributes = $salaryStructure === ContractSalaryStructure::Monthly
                        ? $this->buildMonthlyRecordAttributes($employee, $contract, $timesheet, $workingDaysInPeriod, $lockedPeriod)
                        : $this->buildDailyRecordAttributes($employee, $contract, $timesheet, $workingDaysInPeriod, $lockedPeriod);
                } catch (ValidationException $exception) {
                    $errors[] = PayrollGenerationError::fromValidationException($employee, $exception);

                    continue;
                }

                PayrollRecord::query()->updateOrCreate(
                    [
                        'company_id' => $lockedPeriod->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $lockedPeriod->id,
                    ],
                    $recordAttributes,
                );

                $generatedCount++;
            }

            $periodUpdates = [
                'excluded_employee_ids' => $excludedEmployeeIds,
            ];

            if ($generatedCount > 0 && $lockedPeriod->status === PayrollPeriodStatus::Draft) {
                $periodUpdates['status'] = PayrollPeriodStatus::Processing;
            }

            $lockedPeriod->update($periodUpdates);

            if ($generatedCount > 0) {
                $this->recalculateCrewPayroll->handle($lockedPeriod->fresh());
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
     * @return array<string, mixed>
     */
    private function buildDailyRecordAttributes(
        Employee $employee,
        EmployeeContract $contract,
        CrewTimesheet $timesheet,
        int $workingDaysInPeriod,
        PayrollPeriod $period,
    ): array {
        $calculated = $this->calculator->calculate(
            $timesheet,
            $this->resolveEffectiveComponents->handle($contract, $period->start_date),
            CrewOvertimeMonthlySalary::STANDARD_PERIOD_DAYS,
            $workingDaysInPeriod,
        );

        $breakdown = $calculated['calculation_breakdown'];
        $breakdown['base'] = [
            'gross' => (float) $calculated['gross_salary'],
            'net' => (float) $calculated['net_salary'],
            'bonus' => (float) $calculated['bonus'],
            'other_deductions' => (float) $calculated['other_deductions'],
        ];

        return [
            ...ResolvePayrollRecordSnapshot::from($employee, $contract),
            'payroll_category' => PayrollCategory::Crew,
            'salary_payment_method' => $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer,
            'basic_salary' => $calculated['basic_salary'],
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'other_allowances' => $calculated['other_allowances'],
            'overtime_pay' => $calculated['overtime_pay'],
            'bonus' => $calculated['bonus'],
            'unpaid_leave_deduction' => 0,
            'late_deduction' => 0,
            'loan_deduction' => 0,
            'other_deductions' => $calculated['other_deductions'],
            'total_deductions' => $calculated['total_deductions'],
            'gross_salary' => $calculated['gross_salary'],
            'net_salary' => $calculated['net_salary'],
            'working_days' => $calculated['working_days'],
            'present_days' => (int) round($calculated['present_days']),
            'absent_days' => 0,
            'leave_days' => $calculated['leave_days'],
            'overtime_hours' => $calculated['overtime_hours'],
            'calculation_breakdown' => $breakdown,
            'status' => 'draft',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMonthlyRecordAttributes(
        Employee $employee,
        EmployeeContract $contract,
        CrewTimesheet $timesheet,
        int $workingDaysInPeriod,
        PayrollPeriod $period,
    ): array {
        $calculated = $this->monthlyCalculator->calculate(
            $timesheet,
            $this->resolveEffectiveComponents->handle($contract, $period->start_date),
            $workingDaysInPeriod,
        );

        $breakdown = $calculated['calculation_breakdown'];
        $breakdown['base'] = [
            'basic' => (float) $calculated['basic_salary'],
            'housing' => (float) $calculated['housing_allowance'],
            'transport' => (float) $calculated['transport_allowance'],
            'other' => (float) $calculated['other_allowances'],
            'gross' => (float) $calculated['gross_salary'],
            'net' => (float) $calculated['net_salary'],
            'bonus' => (float) $calculated['bonus'],
            'unpaid_leave_deduction' => (float) $calculated['unpaid_leave_deduction'],
            'other_deductions' => (float) $calculated['other_deductions'],
        ];

        return [
            ...ResolvePayrollRecordSnapshot::from($employee, $contract),
            'payroll_category' => PayrollCategory::Crew,
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
        ];
    }
}
