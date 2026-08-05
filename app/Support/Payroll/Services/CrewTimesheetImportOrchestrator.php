<?php

namespace App\Support\Payroll\Services;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInputType;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Payroll\Actions\RecalculateCrewPayroll;
use App\Support\Payroll\Actions\SyncEmployeeSalaryInputsFromImport;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Payroll\SplitCrewMovementRangeAcrossPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CrewTimesheetImportOrchestrator
{
    public function __construct(
        private readonly CrewTimesheetsImport $import,
        private readonly UpsertCrewTimesheet $upsertCrewTimesheet,
        private readonly SyncEmployeeSalaryInputsFromImport $syncEmployeeSalaryInputsFromImport,
        private readonly RecalculateCrewPayroll $recalculateCrewPayroll,
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly SplitCrewMovementRangeAcrossPeriod $splitRange,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, warnings: int}
     * }
     */
    public function preview(int $companyId, PayrollPeriod $period, UploadedFile $file): array
    {
        $this->assertImportablePeriod($period);

        $parsed = $this->import->parse($file, $companyId);
        $evaluation = $this->evaluateRows(
            $companyId,
            $period,
            $parsed['rows'],
            $parsed['managed_salary_input_type_ids'],
        );

        return [
            'rows' => collect($evaluation['rows'])
                ->map(function (array $row): array {
                    unset($row['employee'], $row['timesheet_data'], $row['salary_amounts_by_type_id']);

                    return $row;
                })
                ->values()
                ->all(),
            'errors' => $evaluation['errors'],
            'warnings' => $evaluation['warnings'],
            'summary' => $evaluation['summary'],
        ];
    }

    /**
     * @return array{imported: int, skipped: int, errors: list<array{row: int, field: string, message: string}>}
     */
    public function execute(
        int $companyId,
        PayrollPeriod $period,
        UploadedFile $file,
        ?int $importedByUserId = null,
    ): array {
        $this->assertImportablePeriod($period);

        $parsed = $this->import->parse($file, $companyId);
        $evaluation = $this->evaluateRows(
            $companyId,
            $period,
            $parsed['rows'],
            $parsed['managed_salary_input_type_ids'],
        );

        if ($evaluation['summary']['valid'] === 0) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows were found to import.',
            ]);
        }

        $imported = 0;
        $skipped = 0;
        $managedTypeIds = $parsed['managed_salary_input_type_ids'];
        $groupedDailyRows = [];

        foreach ($evaluation['rows'] as $row) {
            if (! empty($row['errors'])) {
                $skipped++;

                continue;
            }

            /** @var Employee $employee */
            $employee = $row['employee'];
            $isMonthly = ($row['row_mode'] ?? null) === 'monthly_crew';

            if ($isMonthly) {
                $this->syncEmployeeSalaryInputsFromImport->handle(
                    $period,
                    $employee,
                    $row['salary_amounts_by_type_id'],
                    $managedTypeIds,
                );

                $this->upsertCrewTimesheet->handle(
                    $period,
                    $employee,
                    $row['timesheet_data'],
                    $importedByUserId,
                );

                if (PayrollRecord::query()
                    ->where('company_id', $period->company_id)
                    ->where('period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->where('payroll_category', PayrollCategory::Crew)
                    ->exists()) {
                    $this->recalculateCrewPayroll->handle($period, $employee->id);
                }

                $imported++;

                continue;
            }

            $groupedDailyRows[(int) $employee->id][] = $row;
        }

        foreach ($groupedDailyRows as $employeeRows) {
            /** @var Employee $employee */
            $employee = $employeeRows[0]['employee'];
            $merged = $this->mergeDailyImportRows($employeeRows);

            $this->syncEmployeeSalaryInputsFromImport->handle(
                $period,
                $employee,
                $merged['salary_amounts_by_type_id'],
                $managedTypeIds,
            );

            $this->upsertCrewTimesheet->handle(
                $period,
                $employee,
                $merged['timesheet_data'],
                $importedByUserId,
            );

            if (PayrollRecord::query()
                ->where('company_id', $period->company_id)
                ->where('period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->where('payroll_category', PayrollCategory::Crew)
                ->exists()) {
                $this->recalculateCrewPayroll->handle($period, $employee->id);
            }

            $imported += count($employeeRows);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $evaluation['errors'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{timesheet_data: array<string, mixed>, salary_amounts_by_type_id: array<int, float|int|string|null>}
     */
    private function mergeDailyImportRows(array $rows): array
    {
        $segments = [];
        $overtimeHours = null;
        $unpaidLeaveDays = null;
        $additionalAmount = null;
        $deductionAmount = null;
        $remarks = [];
        $salaryAmounts = [];

        foreach ($rows as $row) {
            $data = $row['timesheet_data'] ?? [];

            foreach ([
                CrewTimesheetPayCategory::SignOnStandby->value => ['sign_on_standby_from', 'sign_on_standby_to', 'sign_on_standby_days'],
                CrewTimesheetPayCategory::Onsite->value => ['onsite_from', 'onsite_to', 'onsite_days'],
                CrewTimesheetPayCategory::SignOffStandby->value => ['sign_off_standby_from', 'sign_off_standby_to', 'sign_off_standby_days'],
            ] as $category => [$fromKey, $toKey, $daysKey]) {
                $from = $data[$fromKey] ?? null;
                $to = $data[$toKey] ?? null;

                if ($from === null || $to === null || $from === '' || $to === '') {
                    continue;
                }

                $segments[] = [
                    'pay_category' => $category,
                    'from_date' => $from,
                    'to_date' => $to,
                    'days' => $data[$daysKey] ?? $this->calculateInclusiveDays(
                        is_string($from) ? $from : null,
                        is_string($to) ? $to : null,
                    ),
                ];
            }

            $overtimeHours = $this->firstEmployeeLevelNumeric($overtimeHours, $data['overtime_hours'] ?? null);
            $unpaidLeaveDays = $this->firstEmployeeLevelNumeric($unpaidLeaveDays, $data['unpaid_leave_days'] ?? null);
            $additionalAmount = $this->firstEmployeeLevelNumeric($additionalAmount, $data['additional_amount'] ?? null);
            $deductionAmount = $this->firstEmployeeLevelNumeric($deductionAmount, $data['deduction_amount'] ?? null);

            if (filled($data['remarks'] ?? null)) {
                $remarks[] = (string) $data['remarks'];
            }

            foreach (($row['salary_amounts_by_type_id'] ?? []) as $typeId => $amount) {
                $normalized = $this->numericOrNull($amount);

                if ($normalized === null || abs($normalized) < 0.00001) {
                    continue;
                }

                $salaryAmounts[(int) $typeId] = $normalized;
            }
        }

        $timesheetData = [
            'source' => CrewTimesheetSource::Import,
            'segments' => $segments,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'overtime_hours' => $overtimeHours ?? 0,
            'additional_amount' => $additionalAmount ?? 0,
            'deduction_amount' => $deductionAmount ?? 0,
            'remarks' => $remarks !== [] ? implode("\n", $remarks) : null,
        ];

        if (count($rows) === 1) {
            $timesheetData = array_merge($rows[0]['timesheet_data'], [
                'segments' => $segments,
            ]);
        }

        return [
            'timesheet_data' => $timesheetData,
            'salary_amounts_by_type_id' => $salaryAmounts,
        ];
    }

    private function firstEmployeeLevelNumeric(mixed $current, mixed $incoming): mixed
    {
        $value = $this->numericOrNull($incoming);

        if ($value === null || abs($value) < 0.00001) {
            return $current;
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $parsedRows
     * @param  list<int>  $managedTypeIds
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, warnings: int}
     * }
     */
    private function evaluateRows(
        int $companyId,
        PayrollPeriod $period,
        array $parsedRows,
        array $managedTypeIds,
    ): array {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
        $contractsByEmployeeId = $this->resolveContract->resolveMany(
            $period,
            $employeesByNo->map(fn (Employee $employee): int => (int) $employee->id)->values()->all(),
        );
        $typeNamesById = $this->loadSalaryInputTypeNames($companyId, $managedTypeIds);
        $existingTimesheets = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->with('preparation')
            ->get()
            ->keyBy(fn (CrewTimesheet $timesheet) => (int) $timesheet->employee_id);
        $seenEmployeeNumbers = [];
        $rows = [];
        $errors = [];
        $warnings = [];
        $exclusiveCrewOperations = $period->requiresExclusiveCrewOperationsTimesheets();

        foreach ($parsedRows as $parsedRow) {
            $rowNumber = (int) $parsedRow['row'];
            $employeeNo = (string) $parsedRow['employee_no'];
            $rowErrors = [];
            $rowWarnings = [];

            if (isset($seenEmployeeNumbers[$employeeNo])) {
                $priorEmployee = $employeesByNo->get($employeeNo);
                $priorContract = $priorEmployee !== null
                    ? $contractsByEmployeeId->get((int) $priorEmployee->id)
                    : null;
                $priorIsMonthly = $priorContract?->resolvedSalaryStructure() === ContractSalaryStructure::Monthly;

                if ($priorIsMonthly || $priorContract === null) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'employee_no',
                        'message' => "Duplicate employee number in file (first seen on row {$seenEmployeeNumbers[$employeeNo]}).",
                    ];
                }
            } else {
                $seenEmployeeNumbers[$employeeNo] = $rowNumber;
            }

            $employee = $employeesByNo->get($employeeNo);
            $contract = $employee !== null
                ? $contractsByEmployeeId->get((int) $employee->id)
                : null;

            if ($employee === null) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => "Employee number {$employeeNo} was not found.",
                ];
            } elseif ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => 'Employee does not have an active crew contract.',
                ];
            }

            $salaryStructure = $contract?->resolvedSalaryStructure()
                ?? ContractSalaryStructure::Daily;
            $isDaily = $salaryStructure === ContractSalaryStructure::Daily;
            $existing = $employee !== null
                ? $existingTimesheets->get((int) $employee->id)
                : null;
            $isLocked = $existing?->isOperationallyLocked() === true;

            $rowMode = 'import_fallback';
            if ($salaryStructure === ContractSalaryStructure::Monthly) {
                $rowMode = 'monthly_crew';
            } elseif ($isLocked) {
                $rowMode = 'crew_operations_locked';
            } elseif ($exclusiveCrewOperations && $isDaily) {
                $rowMode = 'crew_operations_financial';
            }

            $hasOperationalImport = filled($parsedRow['sign_on_standby_from'])
                || filled($parsedRow['sign_on_standby_to'])
                || filled($parsedRow['onsite_from'])
                || filled($parsedRow['onsite_to'])
                || filled($parsedRow['sign_off_standby_from'])
                || filled($parsedRow['sign_off_standby_to']);

            $financialOnly = ($exclusiveCrewOperations && $isDaily) || $isLocked;

            if ($isLocked && $hasOperationalImport) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'sign_on_standby_from',
                    'message' => 'Operational dates are locked from Applied Crew Operations data and cannot be imported.',
                ];
            } elseif ($exclusiveCrewOperations && $isDaily && $hasOperationalImport) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'sign_on_standby_from',
                    'message' => 'Daily crew operational dates cannot be imported in Crew Operations Timeline mode.',
                ];
            }

            $timesheetData = $this->buildTimesheetData($parsedRow, $financialOnly);
            $validator = Validator::make($timesheetData, $this->timesheetRules($financialOnly));

            if ($validator->fails()) {
                foreach ($validator->errors()->keys() as $field) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => $field,
                        'message' => (string) $validator->errors()->first($field),
                    ];
                }
            }

            $movementSplit = $isDaily && ! $financialOnly
                ? $this->buildMovementSplitPreview($timesheetData, $period)
                : [
                    'prior_period_days' => 0.0,
                    'current_period_days' => 0.0,
                    'portions' => [],
                ];

            /** @var array<int, float|string|null> $salaryAmountsByTypeId */
            $salaryAmountsByTypeId = $parsedRow['salary_amounts_by_type_id'] ?? [];

            foreach ($salaryAmountsByTypeId as $typeId => $amount) {
                if ($amount === null || $amount === '') {
                    continue;
                }

                if (! is_numeric($amount)) {
                    $typeName = $typeNamesById[$typeId] ?? 'Salary input';
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => "salary_input_{$typeId}",
                        'message' => "{$typeName} must be a number.",
                    ];

                    continue;
                }

                if ((float) $amount < 0) {
                    $typeName = $typeNamesById[$typeId] ?? 'Salary input';
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => "salary_input_{$typeId}",
                        'message' => "{$typeName} must be at least 0.",
                    ];
                }
            }

            if ($rowMode === 'crew_operations_locked') {
                $rowWarnings[] = [
                    'row' => $rowNumber,
                    'field' => 'source',
                    'message' => 'Operational days are locked from the Applied Crew Operations timeline. Only financial fields will be updated.',
                ];
            }

            $rowResult = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'],
                'department' => $parsedRow['department'],
                'position' => $parsedRow['position'],
                'row_mode' => $rowMode,
                'sign_on_standby_days' => $timesheetData['sign_on_standby_days'] ?? null,
                'onsite_days' => $timesheetData['onsite_days'] ?? null,
                'sign_off_standby_days' => $timesheetData['sign_off_standby_days'] ?? null,
                'total_standby_days' => isset($timesheetData['sign_on_standby_days']) || isset($timesheetData['sign_off_standby_days'])
                    ? round((float) ($timesheetData['sign_on_standby_days'] ?? 0) + (float) ($timesheetData['sign_off_standby_days'] ?? 0), 2)
                    : null,
                'prior_period_days' => $movementSplit['prior_period_days'],
                'current_period_days' => $movementSplit['current_period_days'],
                'movement_split' => $movementSplit['portions'],
                'unpaid_leave_days' => $timesheetData['unpaid_leave_days'] ?? null,
                'overtime_hours' => $timesheetData['overtime_hours'] ?? null,
                'remarks' => $timesheetData['remarks'] ?? null,
                'salary_input_summary' => $this->buildSalaryInputSummary($salaryAmountsByTypeId, $typeNamesById),
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
                'employee' => $employee,
                'timesheet_data' => $timesheetData,
                'salary_amounts_by_type_id' => $this->normalizeSalaryAmountsByTypeId($salaryAmountsByTypeId),
            ];

            $rows[] = $rowResult;
            $errors = array_merge($errors, $rowErrors);
            $warnings = array_merge($warnings, $rowWarnings);
        }

        $rows = $this->applyGroupedDailyImportValidation($rows, $period, $typeNamesById);
        $errors = collect($rows)->flatMap(fn (array $row) => $row['errors'])->values()->all();
        $warnings = collect($rows)->flatMap(fn (array $row) => $row['warnings'])->values()->all();

        $invalidRows = collect($rows)->filter(fn (array $row) => ! empty($row['errors']))->count();

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'total' => count($rows),
                'valid' => count($rows) - $invalidRows,
                'invalid' => $invalidRows,
                'warnings' => count($warnings),
            ],
        ];
    }

    /**
     * Validate grouped Daily Crew rows (repeated employee numbers) for overlaps,
     * period bounds, and employee-level financial values entered once.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $typeNamesById
     * @return list<array<string, mixed>>
     */
    private function applyGroupedDailyImportValidation(
        array $rows,
        PayrollPeriod $period,
        array $typeNamesById,
    ): array {
        $periodEnd = $period->end_date?->toDateString();
        $groupedIndexes = [];

        foreach ($rows as $index => $row) {
            if (($row['row_mode'] ?? null) === 'monthly_crew') {
                continue;
            }

            if (($row['employee'] ?? null) === null) {
                continue;
            }

            $employeeNo = (string) $row['employee_no'];
            $groupedIndexes[$employeeNo][] = $index;
        }

        foreach ($groupedIndexes as $indexes) {
            /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: int, 3: int, 4: string}> $ranges */
            $ranges = [];
            $overtimeRows = [];
            $unpaidLeaveRows = [];
            $additionalRows = [];
            $deductionRows = [];
            /** @var array<int, list<int>> $salaryTypeRows */
            $salaryTypeRows = [];

            foreach ($indexes as $index) {
                $row = $rows[$index];
                $rowNumber = (int) $row['row'];
                $data = $row['timesheet_data'] ?? [];

                foreach ([
                    'sign_on_standby' => ['sign_on_standby_from', 'sign_on_standby_to'],
                    'onsite' => ['onsite_from', 'onsite_to'],
                    'sign_off_standby' => ['sign_off_standby_from', 'sign_off_standby_to'],
                ] as $label => [$fromKey, $toKey]) {
                    $from = $data[$fromKey] ?? null;
                    $to = $data[$toKey] ?? null;

                    if (! filled($from) || ! filled($to)) {
                        continue;
                    }

                    try {
                        $start = CarbonImmutable::parse((string) $from)->startOfDay();
                        $end = CarbonImmutable::parse((string) $to)->startOfDay();
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($periodEnd !== null && $end->toDateString() > $periodEnd) {
                        $rows[$index]['errors'][] = [
                            'row' => $rowNumber,
                            'field' => $toKey,
                            'message' => 'Operational dates cannot extend past the payroll period end.',
                        ];
                    }

                    if ($periodEnd !== null && $start->toDateString() > $periodEnd) {
                        $rows[$index]['errors'][] = [
                            'row' => $rowNumber,
                            'field' => $fromKey,
                            'message' => 'Operational dates cannot extend past the payroll period end.',
                        ];
                    }

                    // Daily Crew may start before the payroll period (prior-period arrears).
                    // Overlap checks use the full submitted range (prior + current portions).
                    $ranges[] = [$start, $end, $rowNumber, $index, $fromKey];
                }

                $this->collectEmployeeLevelNumericRows($overtimeRows, $data['overtime_hours'] ?? null, $rowNumber, $index);
                $this->collectEmployeeLevelNumericRows($unpaidLeaveRows, $data['unpaid_leave_days'] ?? null, $rowNumber, $index);
                $this->collectEmployeeLevelNumericRows($additionalRows, $data['additional_amount'] ?? null, $rowNumber, $index);
                $this->collectEmployeeLevelNumericRows($deductionRows, $data['deduction_amount'] ?? null, $rowNumber, $index);

                foreach (($row['salary_amounts_by_type_id'] ?? []) as $typeId => $amount) {
                    $normalized = $this->numericOrNull($amount);

                    if ($normalized === null || abs($normalized) < 0.00001) {
                        continue;
                    }

                    $salaryTypeRows[(int) $typeId][] = $rowNumber;
                }
            }

            for ($i = 0; $i < count($ranges); $i++) {
                for ($j = $i + 1; $j < count($ranges); $j++) {
                    [$startA, $endA, $rowA, $indexA, $fieldA] = $ranges[$i];
                    [$startB, $endB, $rowB, $indexB, $fieldB] = $ranges[$j];

                    if ($startA->lte($endB) && $startB->lte($endA)) {
                        $message = "Movement periods overlap across Excel rows {$rowA} and {$rowB}.";
                        $rows[$indexA]['errors'][] = [
                            'row' => $rowA,
                            'field' => $fieldA,
                            'message' => $message,
                        ];
                        $rows[$indexB]['errors'][] = [
                            'row' => $rowB,
                            'field' => $fieldB,
                            'message' => $message,
                        ];
                    }
                }
            }

            $this->attachEmployeeLevelDuplicateErrors(
                $rows,
                $overtimeRows,
                'overtime_hours',
                'Overtime hours',
            );
            $this->attachEmployeeLevelDuplicateErrors(
                $rows,
                $unpaidLeaveRows,
                'unpaid_leave_days',
                'Unpaid leave days',
            );
            $this->attachEmployeeLevelDuplicateErrors(
                $rows,
                $additionalRows,
                'additional_amount',
                'Additional amount',
            );
            $this->attachEmployeeLevelDuplicateErrors(
                $rows,
                $deductionRows,
                'deduction_amount',
                'Deduction amount',
            );

            foreach ($salaryTypeRows as $typeId => $rowNumbers) {
                $uniqueRows = array_values(array_unique($rowNumbers));

                if (count($uniqueRows) < 2) {
                    continue;
                }

                $typeName = $typeNamesById[$typeId] ?? 'Salary input';
                $listed = implode(', ', $uniqueRows);
                $message = "{$typeName} is an employee-level amount and must be entered once for the employee (not once per movement). Non-zero values appear on Excel rows {$listed}.";

                foreach ($indexes as $index) {
                    if (! in_array((int) $rows[$index]['row'], $uniqueRows, true)) {
                        continue;
                    }

                    $rows[$index]['errors'][] = [
                        'row' => (int) $rows[$index]['row'],
                        'field' => "salary_input_{$typeId}",
                        'message' => $message,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{row: int, index: int}>  $collector
     */
    private function collectEmployeeLevelNumericRows(array &$collector, mixed $value, int $rowNumber, int $index): void
    {
        $normalized = $this->numericOrNull($value);

        if ($normalized === null || abs($normalized) < 0.00001) {
            return;
        }

        $collector[] = ['row' => $rowNumber, 'index' => $index];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{row: int, index: int}>  $occurrences
     */
    private function attachEmployeeLevelDuplicateErrors(
        array &$rows,
        array $occurrences,
        string $field,
        string $label,
    ): void {
        if (count($occurrences) < 2) {
            return;
        }

        $listed = implode(', ', array_map(fn (array $item): int => $item['row'], $occurrences));
        $message = "{$label} is an employee-level amount and must be entered once for the employee (not once per movement). Non-zero values appear on Excel rows {$listed}.";

        foreach ($occurrences as $occurrence) {
            $rows[$occurrence['index']]['errors'][] = [
                'row' => $occurrence['row'],
                'field' => $field,
                'message' => $message,
            ];
        }
    }

    /**
     * @return Collection<string, Employee>
     */
    private function loadEmployeesByNumber(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->with(['currentContract'])
            ->get()
            ->filter(fn (Employee $employee) => filled($employee->employee_no))
            ->keyBy(fn (Employee $employee) => (string) $employee->employee_no);
    }

    /**
     * @param  list<int>  $managedTypeIds
     * @return array<int, string>
     */
    private function loadSalaryInputTypeNames(int $companyId, array $managedTypeIds): array
    {
        if ($managedTypeIds === []) {
            return [];
        }

        return SalaryInputType::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $managedTypeIds)
            ->pluck('name', 'id')
            ->map(fn (string $name) => $name)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     * @return array<string, mixed>
     */
    private function buildTimesheetData(array $parsedRow, bool $financialOnly = false): array
    {
        if ($financialOnly) {
            $data = ['source' => CrewTimesheetSource::Import];

            if (array_key_exists('overtime_hours', $parsedRow) && $parsedRow['overtime_hours'] !== null && $parsedRow['overtime_hours'] !== '') {
                $data['overtime_hours'] = $parsedRow['overtime_hours'];
            }

            if (array_key_exists('remarks', $parsedRow) && $parsedRow['remarks'] !== null && $parsedRow['remarks'] !== '') {
                $data['remarks'] = $parsedRow['remarks'];
            }

            return $data;
        }

        return [
            'sign_on_standby_from' => $parsedRow['sign_on_standby_from'],
            'sign_on_standby_to' => $parsedRow['sign_on_standby_to'],
            'sign_on_standby_days' => $this->calculateInclusiveDays(
                $parsedRow['sign_on_standby_from'],
                $parsedRow['sign_on_standby_to'],
            ),
            'onsite_from' => $parsedRow['onsite_from'],
            'onsite_to' => $parsedRow['onsite_to'],
            'onsite_days' => $this->calculateInclusiveDays(
                $parsedRow['onsite_from'],
                $parsedRow['onsite_to'],
            ),
            'sign_off_standby_from' => $parsedRow['sign_off_standby_from'],
            'sign_off_standby_to' => $parsedRow['sign_off_standby_to'],
            'sign_off_standby_days' => $this->calculateInclusiveDays(
                $parsedRow['sign_off_standby_from'],
                $parsedRow['sign_off_standby_to'],
            ),
            'unpaid_leave_days' => $this->numericOrNull($parsedRow['unpaid_leave_days'] ?? null),
            'overtime_hours' => $parsedRow['overtime_hours'] ?? 0,
            'additional_amount' => 0,
            'deduction_amount' => 0,
            'remarks' => $parsedRow['remarks'] ?? null,
            'source' => CrewTimesheetSource::Import,
        ];
    }

    /**
     * @param  array<string, mixed>  $timesheetData
     * @return array{
     *     prior_period_days: float,
     *     current_period_days: float,
     *     portions: list<array{
     *         pay_category: string,
     *         from_date: string,
     *         to_date: string,
     *         days: int,
     *         classification: 'prior'|'current'
     *     }>
     * }
     */
    private function buildMovementSplitPreview(array $timesheetData, PayrollPeriod $period): array
    {
        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        if ($periodStart === null || $periodEnd === null) {
            return [
                'prior_period_days' => 0.0,
                'current_period_days' => 0.0,
                'portions' => [],
            ];
        }

        $priorDays = 0.0;
        $currentDays = 0.0;
        $portions = [];

        foreach ([
            CrewTimesheetPayCategory::SignOnStandby->value => ['sign_on_standby_from', 'sign_on_standby_to'],
            CrewTimesheetPayCategory::Onsite->value => ['onsite_from', 'onsite_to'],
            CrewTimesheetPayCategory::SignOffStandby->value => ['sign_off_standby_from', 'sign_off_standby_to'],
        ] as $category => [$fromKey, $toKey]) {
            $from = $timesheetData[$fromKey] ?? null;
            $to = $timesheetData[$toKey] ?? null;

            if (! filled($from) || ! filled($to)) {
                continue;
            }

            $split = $this->splitRange->handle(
                (string) $from,
                (string) $to,
                $periodStart,
                $periodEnd,
            );

            foreach (['prior', 'current'] as $key) {
                $portion = $split[$key] ?? null;

                if ($portion === null) {
                    continue;
                }

                $portions[] = [
                    'pay_category' => $category,
                    'from_date' => $portion['from_date'],
                    'to_date' => $portion['to_date'],
                    'days' => $portion['days'],
                    'classification' => $portion['classification'],
                ];

                if ($portion['classification'] === 'prior') {
                    $priorDays += $portion['days'];
                } else {
                    $currentDays += $portion['days'];
                }
            }
        }

        return [
            'prior_period_days' => round($priorDays, 2),
            'current_period_days' => round($currentDays, 2),
            'portions' => $portions,
        ];
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return array<string, list<string>>
     */
    private function timesheetRules(bool $financialOnly = false): array
    {
        if ($financialOnly) {
            return [
                'overtime_hours' => ['nullable', 'numeric', 'min:0'],
                'additional_amount' => ['nullable', 'numeric', 'min:0'],
                'deduction_amount' => ['nullable', 'numeric', 'min:0'],
                'remarks' => ['nullable', 'string'],
            ];
        }

        return [
            'sign_on_standby_from' => ['nullable', 'date'],
            'sign_on_standby_to' => ['nullable', 'date', 'after_or_equal:sign_on_standby_from'],
            'sign_on_standby_days' => ['nullable', 'numeric', 'min:0'],
            'onsite_from' => ['nullable', 'date'],
            'onsite_to' => ['nullable', 'date', 'after_or_equal:onsite_from'],
            'onsite_days' => ['nullable', 'numeric', 'min:0'],
            'sign_off_standby_from' => ['nullable', 'date'],
            'sign_off_standby_to' => ['nullable', 'date', 'after_or_equal:sign_off_standby_from'],
            'sign_off_standby_days' => ['nullable', 'numeric', 'min:0'],
            'unpaid_leave_days' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'additional_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<int, float|string|null>  $salaryAmountsByTypeId
     * @param  array<int, string>  $typeNamesById
     * @return list<array{name: string, amount: float}>
     */
    private function buildSalaryInputSummary(array $salaryAmountsByTypeId, array $typeNamesById): array
    {
        $summary = [];

        foreach ($salaryAmountsByTypeId as $typeId => $amount) {
            if ($amount === null || $amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $summary[] = [
                'name' => $typeNamesById[$typeId] ?? 'Salary input',
                'amount' => round((float) $amount, 2),
            ];
        }

        return $summary;
    }

    /**
     * @param  array<int, float|string|null>  $salaryAmountsByTypeId
     * @return array<int, float|null>
     */
    private function normalizeSalaryAmountsByTypeId(array $salaryAmountsByTypeId): array
    {
        $normalized = [];

        foreach ($salaryAmountsByTypeId as $typeId => $amount) {
            if ($amount === null || $amount === '') {
                $normalized[(int) $typeId] = null;

                continue;
            }

            $normalized[(int) $typeId] = is_numeric($amount)
                ? round((float) $amount, 2)
                : null;
        }

        return $normalized;
    }

    private function calculateInclusiveDays(?string $from, ?string $to): ?float
    {
        if (! filled($from) || ! filled($to)) {
            return null;
        }

        return round((new CalculateLeaveRequestDays)($from, $to), 2);
    }

    private function assertImportablePeriod(PayrollPeriod $period): void
    {
        if (! $period->isCrew()) {
            throw ValidationException::withMessages([
                'period_id' => 'Crew timesheets can only be imported on crew pay periods.',
            ]);
        }

        if (! $period->isEditable()) {
            throw ValidationException::withMessages([
                'period_id' => 'Timesheets can only be imported for draft payroll periods.',
            ]);
        }
    }
}
