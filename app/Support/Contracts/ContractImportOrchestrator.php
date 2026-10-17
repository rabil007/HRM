<?php

namespace App\Support\Contracts;

use App\Enums\PayrollCategory;
use App\Imports\ContractsImport;
use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ContractImportOrchestrator
{
    /**
     * @var list<string>
     */
    private const COMPENSATION_COLUMNS = [
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowances',
        'eos_monthly_amount',
        'supplementary_allowance',
        'site_allowance',
    ];

    public function __construct(
        private readonly ContractsImport $import,
        private readonly UpsertEmployeeContract $upsertEmployeeContract,
        private readonly ApplyContractSalaryRevision $applySalaryRevision,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, importable: int, skipped: int, warnings: int}
     * }
     */
    public function preview(int $companyId, PayrollCategory $payrollCategory, UploadedFile $file, string $salaryStructure = 'daily'): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $payrollCategory,
            $this->import->parse($file, $payrollCategory, $salaryStructure),
            $salaryStructure,
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
    public function execute(int $companyId, PayrollCategory $payrollCategory, UploadedFile $file, string $salaryStructure = 'daily'): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $payrollCategory,
            $this->import->parse($file, $payrollCategory, $salaryStructure),
            $salaryStructure,
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

                $this->persistImportRow(
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
     * Create/update contract metadata via upsert. Compensation changes on an
     * existing contract go through Salary Revisions so import stays aligned with
     * the ordinary edit guard that rejects direct package mutations.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistImportRow(
        int $companyId,
        Employee $employee,
        array $attributes,
        ?EmployeeContract $existing,
    ): void {
        if ($existing === null) {
            $this->upsertEmployeeContract->handle(
                $companyId,
                $employee,
                $attributes,
            );

            return;
        }

        $beforeAmounts = ApplyContractSalaryRevision::amountsFromContract($existing);
        $proposedAmounts = $beforeAmounts;
        $metadataAttributes = $attributes;

        foreach (self::COMPENSATION_COLUMNS as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $proposedAmounts[$column] = $attributes[$column];
            unset($metadataAttributes[$column]);
        }

        $contract = $this->upsertEmployeeContract->handle(
            $companyId,
            $employee,
            $metadataAttributes,
            $existing,
        );

        if (! ApplyContractSalaryRevision::salaryPackageChanged($beforeAmounts, $proposedAmounts)) {
            return;
        }

        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $applyToPayroll = $category !== PayrollCategory::Crew;
        $effectiveFrom = now()->toDateString();

        $this->applySalaryRevision->handle(
            $contract,
            $proposedAmounts,
            $effectiveFrom,
            'Contract import',
            null,
            $applyToPayroll,
            $applyToPayroll ? $effectiveFrom : null,
            historicalBaselineAmounts: $beforeAmounts,
        );
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
    private function evaluateRows(int $companyId, PayrollCategory $payrollCategory, array $parsedRows, string $salaryStructure): array
    {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
        $companyVisaTypeMap = $this->loadCompanyVisaTypeMap();
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

            $companyVisaTypeId = null;
            $companyVisaTypeName = trim((string) ($parsedRow['company_visa_type'] ?? ''));

            if ($companyVisaTypeName !== '') {
                $normalized = self::normalizeVisaTypeName($companyVisaTypeName);

                if (isset($companyVisaTypeMap[$normalized])) {
                    $companyVisaTypeId = $companyVisaTypeMap[$normalized];
                } else {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'company_visa_type',
                        'message' => "Sponsor \"{$companyVisaTypeName}\" was not found or is inactive.",
                    ];
                }
            }

            $hasContractData = $this->hasContractData($parsedRow);
            $action = 'skip';
            $existingContract = null;
            $contractAttributes = [];
            $effectiveAttributes = [];
            $preservedFields = [];

            if ($hasContractData) {
                if ($employee !== null && $employee->status === 'active') {
                    $existingContract = $this->resolveExistingContract($employee, $payrollCategory);
                }

                $contractAttributes = $this->buildContractAttributes($parsedRow, $payrollCategory, $companyVisaTypeId, $salaryStructure);

                if ($existingContract !== null) {
                    // Blank cells mean "keep the current value" for *all* contract fields.
                    // An operator must use the contract form to clear nullable metadata.
                    foreach ([
                        'start_date', 'end_date', 'labor_contract_id', 'status',
                        'note', 'company_visa_type_id', 'basic_salary',
                        'housing_allowance', 'transport_allowance', 'other_allowances',
                        'eos_monthly_amount', 'supplementary_allowance', 'site_allowance',
                    ] as $field) {
                        if (($contractAttributes[$field] ?? null) === null || $contractAttributes[$field] === '') {
                            unset($contractAttributes[$field]);
                            $preservedFields[] = $field;
                        }
                    }
                }

                // Validate the exact values that the upsert would persist,
                // including original metadata that blank spreadsheet cells retain.
                $effectiveAttributes = $existingContract === null
                    ? $contractAttributes
                    : array_replace([
                        'start_date' => $existingContract->start_date?->toDateString(),
                        'end_date' => $existingContract->end_date?->toDateString(),
                        'labor_contract_id' => $existingContract->labor_contract_id,
                        'status' => $existingContract->status,
                        'company_visa_type_id' => $existingContract->company_visa_type_id,
                        'note' => $existingContract->note,
                        'basic_salary' => $existingContract->basic_salary,
                        'housing_allowance' => $existingContract->housing_allowance,
                        'transport_allowance' => $existingContract->transport_allowance,
                        'other_allowances' => $existingContract->other_allowances,
                        'eos_monthly_amount' => $existingContract->eos_monthly_amount,
                        'supplementary_allowance' => $existingContract->supplementary_allowance,
                        'site_allowance' => $existingContract->site_allowance,
                    ], $contractAttributes);

                $validator = Validator::make($effectiveAttributes, $this->contractRules());

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
                    $desiredStructure = $payrollCategory === PayrollCategory::Office ? 'monthly' : $salaryStructure;

                    if ($existingContract !== null
                        && $payrollCategory === PayrollCategory::Crew
                        && $existingContract->resolvedSalaryStructure()->value !== $desiredStructure) {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'field' => 'salary_structure',
                            'message' => 'Existing active contract has a different salary structure. Use the matching template or transfer workflow.',
                        ];
                    } else {
                        $action = $existingContract === null ? 'create' : 'update';
                    }
                }
            }

            $monthlySalary = $payrollCategory === PayrollCategory::Office || $salaryStructure === 'monthly';
            $salaryComponents = $monthlySalary
                ? ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances']
                : ['basic_salary', 'supplementary_allowance', 'site_allowance'];
            if ($payrollCategory === PayrollCategory::Crew && $salaryStructure === 'monthly') {
                $salaryComponents[] = 'eos_monthly_amount';
            }
            $totalSalary = array_sum(array_map(
                fn (string $field): float => (float) ($effectiveAttributes[$field] ?? 0),
                $salaryComponents,
            ));

            $rowResult = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'],
                'action' => $action,
                'start_date' => $effectiveAttributes['start_date'] ?? null,
                'end_date' => $effectiveAttributes['end_date'] ?? null,
                'labor_contract_id' => $effectiveAttributes['labor_contract_id'] ?? null,
                'status' => $effectiveAttributes['status'] ?? null,
                'salary_structure' => $payrollCategory === PayrollCategory::Office ? 'monthly' : $salaryStructure,
                'basic_salary' => $effectiveAttributes['basic_salary'] ?? null,
                'housing_allowance' => $monthlySalary ? ($effectiveAttributes['housing_allowance'] ?? null) : null,
                'transport_allowance' => $monthlySalary ? ($effectiveAttributes['transport_allowance'] ?? null) : null,
                'other_allowances' => $monthlySalary ? ($effectiveAttributes['other_allowances'] ?? null) : null,
                'supplementary_allowance' => ! $monthlySalary ? ($effectiveAttributes['supplementary_allowance'] ?? null) : null,
                'site_allowance' => ! $monthlySalary ? ($effectiveAttributes['site_allowance'] ?? null) : null,
                'eos_monthly_amount' => $payrollCategory === PayrollCategory::Crew && $salaryStructure === 'monthly'
                    ? ($effectiveAttributes['eos_monthly_amount'] ?? null) : null,
                'total_salary' => $hasContractData ? round($totalSalary, 2) : null,
                'preserved_fields' => $preservedFields,
                'company_visa_type' => ($parsedRow['company_visa_type'] ?? null)
                    ?: $existingContract?->companyVisaType?->name,
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
            ->active()
            ->get()
            ->filter(fn (Employee $employee) => filled($employee->employee_no))
            ->keyBy(fn (Employee $employee) => (string) $employee->employee_no);
    }

    /**
     * Company Visa Types are global, active master data. Only active,
     * non-deleted values can be matched by name during import.
     *
     * @return array<string, int>
     */
    private function loadCompanyVisaTypeMap(): array
    {
        return CompanyVisaType::query()
            ->where('is_active', true)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalizeVisaTypeName((string) $name) => (int) $id])
            ->all();
    }

    private static function normalizeVisaTypeName(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<string, mixed>  $parsedRow
     */
    private function hasContractData(array $parsedRow): bool
    {
        foreach ([
            'start_date',
            'end_date',
            'labor_contract_id',
            'status',
            'basic_salary',
            'housing_allowance',
            'transport_allowance',
            'other_allowances',
            'eos_monthly_amount',
            'supplementary_allowance',
            'site_allowance',
            'note',
            'company_visa_type',
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
    private function buildContractAttributes(array $parsedRow, PayrollCategory $payrollCategory, ?int $companyVisaTypeId, string $salaryStructure): array
    {
        return [
            'start_date' => $parsedRow['start_date'],
            'end_date' => $parsedRow['end_date'],
            'labor_contract_id' => $parsedRow['labor_contract_id'],
            'status' => $parsedRow['status'],
            'payroll_category' => $payrollCategory->value,
            'salary_structure' => $payrollCategory === PayrollCategory::Office ? 'monthly' : $salaryStructure,
            'basic_salary' => $parsedRow['basic_salary'],
            'housing_allowance' => $parsedRow['housing_allowance'],
            'transport_allowance' => $parsedRow['transport_allowance'],
            'other_allowances' => $parsedRow['other_allowances'],
            'eos_monthly_amount' => $parsedRow['eos_monthly_amount'] ?? null,
            'supplementary_allowance' => $parsedRow['supplementary_allowance'],
            'site_allowance' => $parsedRow['site_allowance'],
            'note' => $parsedRow['note'],
            'company_visa_type_id' => $companyVisaTypeId,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function contractRules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,ended'],
            'payroll_category' => ['required', 'in:office,crew'],
            'salary_structure' => ['required', 'in:daily,monthly'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'eos_monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'company_visa_type_id' => ['nullable', 'integer'],
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
