<?php

namespace App\Support\Contracts;

use App\Enums\PayrollCategory;
use App\Imports\ContractsImport;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ContractImportOrchestrator
{
    public function __construct(
        private readonly ContractsImport $import,
        private readonly UpsertEmployeeContract $upsertEmployeeContract,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, importable: int, skipped: int, warnings: int}
     * }
     */
    public function preview(int $companyId, PayrollCategory $payrollCategory, UploadedFile $file): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $payrollCategory,
            $this->import->parse($file, $payrollCategory),
        );

        return [
            'rows' => collect($evaluation['rows'])
                ->map(function (array $row): array {
                    unset($row['employee'], $row['existing_contract'], $row['contract_attributes']);

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
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     errors: list<array{row: int, field: string, message: string}>
     * }
     */
    public function execute(int $companyId, PayrollCategory $payrollCategory, UploadedFile $file): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $payrollCategory,
            $this->import->parse($file, $payrollCategory),
        );

        if ($evaluation['summary']['importable'] === 0) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows were found to import.',
            ]);
        }

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($evaluation, $companyId, &$imported, &$skipped): void {
            foreach ($evaluation['rows'] as $row) {
                if (! empty($row['errors']) || $row['action'] === 'skip') {
                    $skipped++;

                    continue;
                }

                /** @var Employee $employee */
                $employee = $row['employee'];

                $this->upsertEmployeeContract->handle(
                    $companyId,
                    $employee,
                    $row['contract_attributes'],
                    $row['existing_contract'],
                );

                $imported++;
            }
        });

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
     *     summary: array{total: int, valid: int, invalid: int, importable: int, skipped: int, warnings: int}
     * }
     */
    private function evaluateRows(int $companyId, PayrollCategory $payrollCategory, array $parsedRows): array
    {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
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
            } elseif ($employee->status !== 'active') {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => 'Employee is not active.',
                ];
            }

            $hasContractData = $this->hasContractData($parsedRow);
            $action = 'skip';
            $existingContract = null;
            $contractAttributes = [];

            if ($hasContractData) {
                $contractAttributes = $this->buildContractAttributes($parsedRow, $payrollCategory);
                $validator = Validator::make($contractAttributes, $this->contractRules());

                if ($validator->fails()) {
                    foreach ($validator->errors()->keys() as $field) {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'field' => $field,
                            'message' => (string) $validator->errors()->first($field),
                        ];
                    }
                }

                if ($employee !== null && empty($rowErrors)) {
                    $existingContract = $this->resolveExistingContract($employee, $payrollCategory);
                    $action = $existingContract === null ? 'create' : 'update';
                }
            }

            $rowResult = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'],
                'action' => $action,
                'contract_type' => $contractAttributes['contract_type'] ?? null,
                'start_date' => $contractAttributes['start_date'] ?? null,
                'end_date' => $contractAttributes['end_date'] ?? null,
                'labor_contract_id' => $contractAttributes['labor_contract_id'] ?? null,
                'status' => $contractAttributes['status'] ?? null,
                'basic_salary' => $contractAttributes['basic_salary'] ?? null,
                'errors' => $rowErrors,
                'warnings' => [],
                'employee' => $employee,
                'existing_contract' => $existingContract,
                'contract_attributes' => $contractAttributes,
            ];

            $rows[] = $rowResult;
            $errors = array_merge($errors, $rowErrors);
        }

        $invalidRows = collect($rows)->filter(fn (array $row) => ! empty($row['errors']))->count();
        $importableRows = collect($rows)->filter(
            fn (array $row) => empty($row['errors']) && in_array($row['action'], ['create', 'update'], true),
        )->count();
        $skippedRows = collect($rows)->filter(
            fn (array $row) => empty($row['errors']) && $row['action'] === 'skip',
        )->count();

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
            'summary' => [
                'total' => count($rows),
                'valid' => count($rows) - $invalidRows,
                'invalid' => $invalidRows,
                'importable' => $importableRows,
                'skipped' => $skippedRows,
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
            ->get()
            ->filter(fn (Employee $employee) => filled($employee->employee_no))
            ->keyBy(fn (Employee $employee) => (string) $employee->employee_no);
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     */
    private function hasContractData(array $parsedRow): bool
    {
        foreach ([
            'contract_type',
            'start_date',
            'end_date',
            'labor_contract_id',
            'status',
            'basic_salary',
            'housing_allowance',
            'transport_allowance',
            'other_allowances',
            'supplementary_allowance',
            'site_allowance',
            'note',
        ] as $field) {
            if (filled($parsedRow[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     * @return array<string, mixed>
     */
    private function buildContractAttributes(array $parsedRow, PayrollCategory $payrollCategory): array
    {
        return [
            'contract_type' => $parsedRow['contract_type'],
            'start_date' => $parsedRow['start_date'],
            'end_date' => $parsedRow['end_date'],
            'labor_contract_id' => $parsedRow['labor_contract_id'],
            'status' => $parsedRow['status'],
            'payroll_category' => $payrollCategory->value,
            'basic_salary' => $parsedRow['basic_salary'],
            'housing_allowance' => $parsedRow['housing_allowance'],
            'transport_allowance' => $parsedRow['transport_allowance'],
            'other_allowances' => $parsedRow['other_allowances'],
            'supplementary_allowance' => $parsedRow['supplementary_allowance'],
            'site_allowance' => $parsedRow['site_allowance'],
            'note' => $parsedRow['note'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function contractRules(): array
    {
        return [
            'contract_type' => ['required', 'in:limited,unlimited,part_time,contract'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,ended'],
            'payroll_category' => ['required', 'in:office,crew'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function resolveExistingContract(
        Employee $employee,
        PayrollCategory $payrollCategory,
    ): ?EmployeeContract {
        return EmployeeContract::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->where('payroll_category', $payrollCategory->value)
            ->first();
    }
}
