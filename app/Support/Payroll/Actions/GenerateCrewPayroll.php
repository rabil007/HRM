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
use App\Support\Payroll\AssertCrewPayrollCalculationFreshness;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\BuildDailyCrewPayrollAllocationPlan;
use App\Support\Payroll\CrewMonthlyPayrollCalculator;
use App\Support\Payroll\CrewOvertimeMonthlySalary;
use App\Support\Payroll\CrewPayrollCalculator;
use App\Support\Payroll\GeneratePayrollResult;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\PayrollGenerationError;
use App\Support\Payroll\PersistPayrollWorkAllocations;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Payroll\ResolveEffectiveContractSalaryComponents;
use App\Support\Payroll\ResolvePayrollRecordSnapshot;
use App\Support\Settings\CompanyCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        private readonly BuildDailyCrewPayrollAllocationPlan $buildAllocationPlan,
        private readonly PersistPayrollWorkAllocations $persistAllocations,
        private readonly AssertCrewPayrollCalculationFreshness $calculationFreshness,
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
                        ->with(['preparation', 'segments']),
                ])
                ->orderBy('employees.name')
                ->get();

            $resolvedContracts = $this->resolveContract->resolveMany(
                $lockedPeriod,
                $employees->pluck('id')->map(intval(...))->all(),
                ['salaryComponents', 'salaryRevisions.lines'],
            );

            $currencyCode = CompanyCurrency::codeForCompany((int) $lockedPeriod->company_id);

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

                $existing = $existingRecords->get((int) $employee->id);
                $ignorePayrollRecordId = ($existing !== null && ! $existing->trashed())
                    ? (int) $existing->id
                    : null;

                $allocationPlan = null;

                try {
                    if ($salaryStructure === ContractSalaryStructure::Monthly) {
                        $recordAttributes = $this->buildMonthlyRecordAttributes(
                            $employee,
                            $contract,
                            $timesheet,
                            $workingDaysInPeriod,
                            $lockedPeriod,
                            $currencyCode,
                        );
                    } else {
                        [$recordAttributes, $allocationPlan] = $this->buildDailyRecordAttributes(
                            $employee,
                            $contract,
                            $timesheet,
                            $workingDaysInPeriod,
                            $lockedPeriod,
                            $ignorePayrollRecordId,
                            $currencyCode,
                        );
                    }
                } catch (ValidationException $exception) {
                    $errors[] = PayrollGenerationError::fromValidationException($employee, $exception);

                    continue;
                }

                if ($existing !== null) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $existing->fill($recordAttributes);
                    $existing->save();
                    $record = $existing;
                } else {
                    $record = PayrollRecord::query()->create([
                        'company_id' => $lockedPeriod->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $lockedPeriod->id,
                        ...$recordAttributes,
                    ]);
                }

                if ($allocationPlan !== null) {
                    // Uniqueness conflicts surface as ValidationException from Persist
                    // and abort the outer transaction so no partial record remains.
                    $this->persistAllocations->replaceForRecord(
                        $lockedPeriod,
                        $record,
                        $allocationPlan['days'],
                        $timesheet->id ? (int) $timesheet->id : null,
                    );

                    if ((int) ($allocationPlan['payable_prior_days'] ?? 0) > 0) {
                        activity()
                            ->performedOn($record)
                            ->withProperties([
                                'event' => 'crew_payroll_prior_period_arrears_included',
                                'company_id' => (int) $lockedPeriod->company_id,
                                'payroll_period_id' => (int) $lockedPeriod->id,
                                'payroll_record_id' => (int) $record->id,
                                'employee_id' => (int) $employee->id,
                                'requested_prior_days' => (int) ($allocationPlan['requested_prior_days'] ?? 0),
                                'payable_prior_days' => (int) $allocationPlan['payable_prior_days'],
                                'excluded_already_paid_count' => count($allocationPlan['excluded_already_paid'] ?? []),
                            ])
                            ->log('Prior-period arrears included in crew payroll');
                    }
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
     * Soft-delete draft payroll rows for skipped/excluded employees.
     * Releases reserved allocation locks first so work dates become available again.
     * Never touches approved/paid records or finalized periods.
     *
     * @param  list<int>  $employeeIds
     */
    private function softDeleteDraftRecordsForEmployees(PayrollPeriod $period, array $employeeIds): void
    {
        if ($employeeIds === [] || ! $period->canGenerateCrewPayroll()) {
            return;
        }

        // Excluded and not-ready employees must release reserved date locks before soft-delete.
        $this->persistAllocations->releaseReservedForEmployees($period, $employeeIds);

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
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function buildDailyRecordAttributes(
        Employee $employee,
        EmployeeContract $contract,
        CrewTimesheet $timesheet,
        int $workingDaysInPeriod,
        PayrollPeriod $period,
        ?int $ignorePayrollRecordId = null,
        ?string $currencyCode = null,
    ): array {
        $timesheet->loadMissing(['segments']);

        $components = $this->resolveEffectiveComponents->handle($contract, $period->start_date);
        $hasMovementLines = $timesheet->segments->isNotEmpty();

        $allocationPlan = null;

        if ($hasMovementLines) {
            $allocationPlan = $this->buildAllocationPlan->handleOrFail(
                $period,
                $timesheet,
                $ignorePayrollRecordId,
            );

            $calculated = $this->calculator->calculate(
                $timesheet,
                $components,
                CrewOvertimeMonthlySalary::STANDARD_PERIOD_DAYS,
                $workingDaysInPeriod,
                $allocationPlan,
            );
        } else {
            $calculated = $this->calculator->calculate(
                $timesheet,
                $components,
                CrewOvertimeMonthlySalary::STANDARD_PERIOD_DAYS,
                $workingDaysInPeriod,
            );
        }

        $breakdown = $calculated['calculation_breakdown'];
        $breakdown['base'] = [
            'gross' => (float) $calculated['gross_salary'],
            'net' => (float) $calculated['net_salary'],
            'bonus' => (float) $calculated['bonus'],
            'other_deductions' => (float) $calculated['other_deductions'],
        ];
        $breakdown['currency_code'] = $currencyCode ?? CompanyCurrency::codeForCompany((int) $period->company_id);

        if ($allocationPlan !== null) {
            $timesheet->loadMissing(['segments']);
            $breakdown['source_fingerprint'] = $this->calculationFreshness->fingerprint(
                $timesheet,
                $timesheet->segments,
                $allocationPlan['days'] ?? [],
            );
        }

        $attributes = [
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

        static $hasCurrencyColumn = null;
        $hasCurrencyColumn ??= Schema::hasColumn('payroll_records', 'currency_code');

        if ($hasCurrencyColumn) {
            $attributes['currency_code'] = $breakdown['currency_code'];
        }

        return [$attributes, $allocationPlan];
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
        ?string $currencyCode = null,
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
        $breakdown['currency_code'] = $currencyCode ?? CompanyCurrency::codeForCompany((int) $period->company_id);

        $attributes = [
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

        static $hasCurrencyColumn = null;
        $hasCurrencyColumn ??= Schema::hasColumn('payroll_records', 'currency_code');

        if ($hasCurrencyColumn) {
            $attributes['currency_code'] = $breakdown['currency_code'];
        }

        return $attributes;
    }
}
