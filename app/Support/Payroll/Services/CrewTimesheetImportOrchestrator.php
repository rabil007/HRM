<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CrewTimesheetImportOrchestrator
{
    private const RATE_TOLERANCE = 0.01;

    public function __construct(
        private readonly CrewTimesheetsImport $import,
        private readonly UpsertCrewTimesheet $upsertCrewTimesheet,
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

        $evaluation = $this->evaluateRows($companyId, $period, $this->import->parse($file));

        return [
            'rows' => collect($evaluation['rows'])
                ->map(function (array $row): array {
                    unset($row['employee'], $row['timesheet_data']);

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

        $evaluation = $this->evaluateRows($companyId, $period, $this->import->parse($file));

        if ($evaluation['summary']['valid'] === 0) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows were found to import.',
            ]);
        }

        $imported = 0;
        $skipped = 0;

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
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, warnings: int}
     * }
     */
    private function evaluateRows(int $companyId, PayrollPeriod $period, array $parsedRows): array
    {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
        $seenEmployeeNumbers = [];
        $rows = [];
        $errors = [];
        $warnings = [];

        foreach ($parsedRows as $parsedRow) {
            $rowNumber = (int) $parsedRow['row'];
            $employeeNo = (string) $parsedRow['employee_no'];
            $rowErrors = [];
            $rowWarnings = [];

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

            if ($employee !== null && $employee->currentContract !== null) {
                $rowWarnings = array_merge(
                    $rowWarnings,
                    $this->rateMismatchWarnings($rowNumber, $parsedRow, $employee),
                );
            }

            $rowResult = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'],
                'designation' => $parsedRow['designation'],
                'client' => $parsedRow['client'],
                'project' => $parsedRow['project'],
                'standby_days' => $timesheetData['standby_days'],
                'onsite_days' => $timesheetData['onsite_days'],
                'overtime_amount' => $timesheetData['overtime_amount'],
                'additional_amount' => $timesheetData['additional_amount'],
                'deduction_amount' => $timesheetData['deduction_amount'],
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
                'employee' => $employee,
                'timesheet_data' => $timesheetData,
            ];

            $rows[] = $rowResult;
            $errors = array_merge($errors, $rowErrors);
            $warnings = array_merge($warnings, $rowWarnings);
        }

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
     * @return Collection<string, Employee>
     */
    private function loadEmployeesByNumber(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->with(['currentContract.salaryComponents'])
            ->get()
            ->filter(fn (Employee $employee) => filled($employee->employee_no))
            ->keyBy(fn (Employee $employee) => (string) $employee->employee_no);
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     * @return array<string, mixed>
     */
    private function buildTimesheetData(array $parsedRow): array
    {
        $remarks = collect([
            filled($parsedRow['client'] ?? null) ? 'Client: '.$parsedRow['client'] : null,
            filled($parsedRow['project'] ?? null) ? 'Project: '.$parsedRow['project'] : null,
            filled($parsedRow['payment_method'] ?? null) ? 'Payment: '.$parsedRow['payment_method'] : null,
        ])->filter()->implode(' | ');

        return [
            'standby_from' => $parsedRow['standby_from'],
            'standby_to' => $parsedRow['standby_to'],
            'standby_days' => $parsedRow['standby_days'],
            'onsite_from' => $parsedRow['onsite_from'],
            'onsite_to' => $parsedRow['onsite_to'],
            'onsite_days' => $parsedRow['onsite_days'],
            'overtime_amount' => $parsedRow['overtime_amount'] ?? 0,
            'additional_amount' => $parsedRow['additional_amount'] ?? 0,
            'deduction_amount' => $parsedRow['deduction_amount'] ?? 0,
            'remarks' => $remarks !== '' ? $remarks : null,
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
            'overtime_amount' => ['nullable', 'numeric', 'min:0'],
            'additional_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     * @return list<array{row: int, field: string, message: string}>
     */
    private function rateMismatchWarnings(int $rowNumber, array $parsedRow, Employee $employee): array
    {
        $warnings = [];
        $components = $employee->currentContract?->salaryComponents
            ->where('status', SalaryComponentStatus::Active) ?? collect();

        $comparisons = [
            'file_basic_rate' => SalaryComponentCode::Basic,
            'file_supplementary_rate' => SalaryComponentCode::SupplementaryAllowance,
            'file_site_rate' => SalaryComponentCode::SiteAllowance,
        ];

        foreach ($comparisons as $fileField => $code) {
            $fileRate = $parsedRow[$fileField] ?? null;

            if ($fileRate === null) {
                continue;
            }

            $contractRate = $components
                ->firstWhere('component_code', $code)
                ?->amount;

            if ($contractRate === null) {
                continue;
            }

            if (abs((float) $fileRate - (float) $contractRate) > self::RATE_TOLERANCE) {
                $warnings[] = [
                    'row' => $rowNumber,
                    'field' => $fileField,
                    'message' => "File {$code->label()} rate ({$fileRate}) differs from contract rate ({$contractRate}).",
                ];
            }
        }

        return $warnings;
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
