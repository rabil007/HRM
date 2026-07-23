<?php

namespace App\Support\Payroll\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewMonthlyPayrollCalculator;
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
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly BuildCrewPayrollGenerationPreview $buildPreview,
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

        $generatedCount = 0;
        $skippedEmployees = [];
        $errors = [];
        $skippedMissing = 0;
        $skippedAwaiting = 0;
        $previewArray = null;
        $workingDaysInPeriod = $period->calendarDayCount();

        DB::transaction(function () use (
            $period,
            $excludedEmployeeIds,
            $workingDaysInPeriod,
            &$generatedCount,
            &$skippedEmployees,
            &$errors,
            &$skippedMissing,
            &$skippedAwaiting,
            &$previewArray,
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

            $preview = $this->buildPreview->handle(
                $lockedPeriod,
                (int) $lockedPeriod->company_id,
                $excludedEmployeeIds,
            );
            $previewArray = $preview->toArray();

            if ($preview->blockingCount > 0) {
                throw ValidationException::withMessages([
                    'period_id' => $preview->blockingIssues[0]['message']
                        ?? 'Payroll generation is blocked by invalid approved timesheet data.',
                ]);
            }

            if ($preview->readyCount === 0) {
                throw ValidationException::withMessages([
                    'period_id' => 'No employees are ready for payroll.',
                ]);
            }

            $readyIds = $preview->readyEmployeeIds;
            $skippedMissing = $preview->missingTimesheetCount;
            $skippedAwaiting = $preview->awaitingApprovalCount;

            foreach ($preview->missingTimesheetEmployeeIds as $employeeId) {
                $skippedEmployees[] = [
                    'id' => $employeeId,
                    'name' => '',
                    'employee_no' => null,
                    'reason' => 'missing_timesheet',
                ];
            }

            foreach ($preview->awaitingApprovalEmployeeIds as $employeeId) {
                $skippedEmployees[] = [
                    'id' => $employeeId,
                    'name' => '',
                    'employee_no' => null,
                    'reason' => 'awaiting_approval',
                ];
            }

            $notReadyIds = array_values(array_unique(array_merge(
                $preview->missingTimesheetEmployeeIds,
                $preview->awaitingApprovalEmployeeIds,
                $excludedEmployeeIds,
            )));

            $this->softDeleteDraftRecordsForEmployees($lockedPeriod, $notReadyIds);

            $existingRecords = PayrollRecord::withTrashed()
                ->where('company_id', $lockedPeriod->company_id)
                ->where('period_id', $lockedPeriod->id)
                ->whereIn('employee_id', $readyIds !== [] ? $readyIds : [0])
                ->get()
                ->keyBy(fn (PayrollRecord $record) => (int) $record->employee_id);

            $employees = PayrollEmployeeQuery::activeQuery(
                (int) $lockedPeriod->company_id,
                PayrollCategory::Crew,
            )
                ->whereIn('employees.id', $readyIds !== [] ? $readyIds : [0])
                ->with([
                    'primaryBankAccount',
                    'crewTimesheets' => fn ($query) => $query
                        ->where('period_id', $lockedPeriod->id)
                        ->with('preparation'),
                ])
                ->orderBy('employees.name')
                ->get();

            $resolvedContracts = $this->resolveContract->resolveMany(
                $lockedPeriod,
                $employees->pluck('id')->map(intval(...))->all(),
                ['salaryComponents', 'salaryRevisions.lines'],
            );

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
                    if ($salaryStructure === ContractSalaryStructure::Monthly) {
                        $timesheet = new CrewTimesheet([
                            'unpaid_leave_days' => 0,
                            'overtime_hours' => 0,
                            'additional_amount' => 0,
                            'deduction_amount' => 0,
                            'source' => CrewTimesheetSource::Manual,
                        ]);
                    } else {
                        continue;
                    }
                }

                try {
                    $recordAttributes = $salaryStructure === ContractSalaryStructure::Monthly
                        ? $this->buildMonthlyRecordAttributes($employee, $contract, $timesheet, $workingDaysInPeriod, $lockedPeriod)
                        : $this->buildDailyRecordAttributes($employee, $contract, $timesheet, $workingDaysInPeriod, $lockedPeriod);
                } catch (ValidationException $exception) {
                    $errors[] = PayrollGenerationError::fromValidationException($employee, $exception);

                    continue;
                }

                $existing = $existingRecords->get((int) $employee->id);

                if ($existing !== null) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $existing->fill($recordAttributes);
                    $existing->save();
                } else {
                    PayrollRecord::query()->create([
                        'company_id' => $lockedPeriod->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $lockedPeriod->id,
                        ...$recordAttributes,
                    ]);
                }

                $generatedCount++;
            }

            $periodUpdates = [
                'excluded_employee_ids' => $excludedEmployeeIds,
            ];

            if ($generatedCount > 0) {
                $periodUpdates['generated_at'] = now();

                if ($lockedPeriod->status === PayrollPeriodStatus::Draft) {
                    $periodUpdates['status'] = PayrollPeriodStatus::Processing;
                }
            }

            $lockedPeriod->update($periodUpdates);

            if ($generatedCount > 0 && $this->periodHasSalaryInputs($lockedPeriod, $readyIds)) {
                $this->recalculateCrewPayroll->handle($lockedPeriod->fresh());
            }
        });

        $employeeNames = Employee::query()
            ->where('company_id', $period->company_id)
            ->whereIn('id', array_column($skippedEmployees, 'id') ?: [0])
            ->get(['id', 'name', 'employee_no'])
            ->keyBy('id');

        $skippedEmployees = array_map(function (array $row) use ($employeeNames): array {
            $employee = $employeeNames->get($row['id']);

            return [
                'id' => $row['id'],
                'name' => $employee?->name ?? $row['name'],
                'employee_no' => $employee?->employee_no,
                'reason' => $row['reason'] ?? 'skipped',
            ];
        }, $skippedEmployees);

        activity()
            ->performedOn($period)
            ->withProperties([
                'event' => 'crew_payroll_generated',
                'company_id' => $period->company_id,
                'payroll_period_id' => $period->id,
                'generated_count' => $generatedCount,
                'skipped_missing_timesheet_count' => $skippedMissing,
                'skipped_awaiting_approval_count' => $skippedAwaiting,
                'skipped_excluded_count' => count($excludedEmployeeIds),
            ])
            ->log('Crew payroll generated');

        return new GeneratePayrollResult(
            generatedCount: $generatedCount,
            skippedCount: count($skippedEmployees) + count($excludedEmployeeIds),
            skippedEmployees: $skippedEmployees,
            errors: $errors,
            skippedMissingTimesheetCount: $skippedMissing,
            skippedAwaitingApprovalCount: $skippedAwaiting,
            skippedExcludedCount: count($excludedEmployeeIds),
            preview: $previewArray,
        );
    }

    /**
     * Soft-delete draft payroll rows for skipped employees. Never touches
     * approved/paid records or finalized periods.
     *
     * @param  list<int>  $employeeIds
     */
    private function softDeleteDraftRecordsForEmployees(PayrollPeriod $period, array $employeeIds): void
    {
        if ($employeeIds === [] || ! $period->canGenerateCrewPayroll()) {
            return;
        }

        PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'draft')
            ->delete();
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function periodHasSalaryInputs(PayrollPeriod $period, array $employeeIds): bool
    {
        if ($employeeIds === []) {
            return false;
        }

        return SalaryInput::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->exists();
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
