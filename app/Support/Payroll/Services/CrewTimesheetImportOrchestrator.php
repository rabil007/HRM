<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInputType;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Payroll\Actions\RecalculateCrewPayroll;
use App\Support\Payroll\Actions\SyncEmployeeSalaryInputsFromImport;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
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
    public function execute(int $companyId, PayrollPeriod $period, UploadedFile $file): array
    {
        $this->assertImportablePeriod($period);

        $parsed = $this->import->parse($file, $companyId);
        $evaluation = $this->evaluateRows(
            $companyId,
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

        foreach ($evaluation['rows'] as $row) {
            if (! empty($row['errors'])) {
                $skipped++;

                continue;
            }

            /** @var Employee $employee */
            $employee = $row['employee'];

            $this->upsertCrewTimesheet->handle(
                $period,
                $employee,
                $row['timesheet_data'],
            );

            if ($managedTypeIds !== []) {
                $this->syncEmployeeSalaryInputsFromImport->handle(
                    $period,
                    $employee,
                    $row['salary_amounts_by_type_id'],
                    $managedTypeIds,
                );
            }

            if (PayrollRecord::query()
                ->where('company_id', $period->company_id)
                ->where('period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->where('payroll_category', PayrollCategory::Crew)
                ->exists()) {
                $this->recalculateCrewPayroll->handle($period, $employee->id);
            }

            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $evaluation['errors'],
        ];
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
        array $parsedRows,
        array $managedTypeIds,
    ): array {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
        $typeNamesById = $this->loadSalaryInputTypeNames($companyId, $managedTypeIds);
        $seenEmployeeNumbers = [];
        $rows = [];
        $errors = [];

        foreach ($parsedRows as $parsedRow) {
            $rowNumber = (int) $parsedRow['row'];
            $employeeNo = (string) $parsedRow['employee_no'];
            $rowErrors = [];

            if (isset($seenEmployeeNumbers[$employeeNo])) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => "Duplicate employee number in file (first seen on row {$seenEmployeeNumbers[$employeeNo]}).",
                ];
            } else {
                $seenEmployeeNumbers[$employeeNo] = $rowNumber;
            }

            $employee = $employeesByNo->get($employeeNo);

            if ($employee === null) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => "Employee number {$employeeNo} was not found.",
                ];
            } elseif ($employee->currentContract?->payroll_category !== PayrollCategory::Crew) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => 'Employee does not have an active crew contract.',
                ];
            }

            $timesheetData = $this->buildTimesheetData($parsedRow);
            $validator = Validator::make($timesheetData, $this->timesheetRules());

            if ($validator->fails()) {
                foreach ($validator->errors()->keys() as $field) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => $field,
                        'message' => (string) $validator->errors()->first($field),
                    ];
                }
            }

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

            $rowResult = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'],
                'department' => $parsedRow['department'],
                'position' => $parsedRow['position'],
                'standby_days' => $timesheetData['standby_days'],
                'onsite_days' => $timesheetData['onsite_days'],
                'overtime_hours' => $timesheetData['overtime_hours'],
                'additional_amount' => $timesheetData['additional_amount'],
                'deduction_amount' => $timesheetData['deduction_amount'],
                'remarks' => $timesheetData['remarks'],
                'salary_input_summary' => $this->buildSalaryInputSummary($salaryAmountsByTypeId, $typeNamesById),
                'errors' => $rowErrors,
                'warnings' => [],
                'employee' => $employee,
                'timesheet_data' => $timesheetData,
                'salary_amounts_by_type_id' => $this->normalizeSalaryAmountsByTypeId($salaryAmountsByTypeId),
            ];

            $rows[] = $rowResult;
            $errors = array_merge($errors, $rowErrors);
        }

        $invalidRows = collect($rows)->filter(fn (array $row) => ! empty($row['errors']))->count();

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
            'summary' => [
                'total' => count($rows),
                'valid' => count($rows) - $invalidRows,
                'invalid' => $invalidRows,
                'warnings' => 0,
            ],
        ];
    }

    /**
     * @return Collection<string, Employee>
     */
    private function loadEmployeesByNumber(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
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
    private function buildTimesheetData(array $parsedRow): array
    {
        return [
            'standby_from' => $parsedRow['standby_from'],
            'standby_to' => $parsedRow['standby_to'],
            'standby_days' => $this->calculateInclusiveDays(
                $parsedRow['standby_from'],
                $parsedRow['standby_to'],
            ),
            'onsite_from' => $parsedRow['onsite_from'],
            'onsite_to' => $parsedRow['onsite_to'],
            'onsite_days' => $this->calculateInclusiveDays(
                $parsedRow['onsite_from'],
                $parsedRow['onsite_to'],
            ),
            'overtime_hours' => $parsedRow['overtime_hours'] ?? 0,
            'additional_amount' => $parsedRow['additional_amount'] ?? 0,
            'deduction_amount' => $parsedRow['deduction_amount'] ?? 0,
            'remarks' => $parsedRow['remarks'] ?? null,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function timesheetRules(): array
    {
        return [
            'standby_from' => ['nullable', 'date'],
            'standby_to' => ['nullable', 'date', 'after_or_equal:standby_from'],
            'standby_days' => ['nullable', 'numeric', 'min:0'],
            'onsite_from' => ['nullable', 'date'],
            'onsite_to' => ['nullable', 'date', 'after_or_equal:onsite_from'],
            'onsite_days' => ['nullable', 'numeric', 'min:0'],
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
