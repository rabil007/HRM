<?php

namespace App\Support\BankAccounts;

use App\Http\Controllers\Organization\EmployeeBankAccountController;
use App\Imports\BankAccountsImport;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankAccountImportOrchestrator
{
    public function __construct(
        private readonly BankAccountsImport $import,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, importable: int, skipped: int, warnings: int}
     * }
     */
    public function preview(int $companyId, UploadedFile $file): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $this->import->parse($file),
        );

        return [
            'rows' => collect($evaluation['rows'])
                ->map(function (array $row): array {
                    unset($row['employee'], $row['existing_account'], $row['account_attributes']);

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
    public function execute(int $companyId, UploadedFile $file): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $this->import->parse($file),
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
                $attributes = $row['account_attributes'];
                /** @var ?EmployeeBankAccount $existing */
                $existing = $row['existing_account'];

                if ($attributes['is_primary'] ?? false) {
                    EmployeeBankAccount::query()
                        ->where('company_id', $companyId)
                        ->where('employee_id', $employee->id)
                        ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
                        ->update(['is_primary' => false]);
                }

                if ($existing === null) {
                    EmployeeBankAccount::query()->create([
                        'company_id' => $companyId,
                        'employee_id' => $employee->id,
                        'bank_id' => $attributes['bank_id'],
                        'iban' => $attributes['iban'],
                        'account_name' => $attributes['account_name'],
                        'is_primary' => $attributes['is_primary'] ?? true,
                    ]);
                } else {
                    $existing->update([
                        'bank_id' => $attributes['bank_id'],
                        'iban' => $attributes['iban'],
                        'account_name' => $attributes['account_name'],
                        'is_primary' => $attributes['is_primary'] ?? $existing->is_primary,
                    ]);
                }

                EmployeeBankAccountController::reconcilePrimaryAccounts($companyId, $employee->id);

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
    private function evaluateRows(int $companyId, array $parsedRows): array
    {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
        $banks = Bank::query()->where('is_active', true)->get();
        $banksByName = $banks->keyBy(fn (Bank $bank) => mb_strtolower(trim((string) $bank->name)));
        $banksById = $banks->keyBy('id');

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

            $hasAccountData = $this->hasAccountData($parsedRow);
            $action = 'skip';
            $existingAccount = null;
            $accountAttributes = [];

            if ($hasAccountData) {
                $bankNameInput = trim((string) ($parsedRow['bank_name'] ?? ''));
                $bank = null;

                if (filled($bankNameInput)) {
                    if (is_numeric($bankNameInput) && isset($banksById[(int) $bankNameInput])) {
                        $bank = $banksById[(int) $bankNameInput];
                    } elseif (isset($banksByName[mb_strtolower($bankNameInput)])) {
                        $bank = $banksByName[mb_strtolower($bankNameInput)];
                    }
                }

                if ($bank === null) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'bank_name',
                        'message' => "Bank '{$bankNameInput}' was not found or is inactive.",
                    ];
                }

                $iban = filled($parsedRow['iban']) ? trim((string) $parsedRow['iban']) : null;
                $accountName = filled($parsedRow['account_name']) ? trim((string) $parsedRow['account_name']) : null;

                if ($iban !== null && mb_strlen($iban) > 50) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'iban',
                        'message' => 'IBAN must not exceed 50 characters.',
                    ];
                }

                if ($accountName !== null && mb_strlen($accountName) > 200) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'account_name',
                        'message' => 'Account name must not exceed 200 characters.',
                    ];
                }

                if ($employee !== null && empty($rowErrors)) {
                    $existingAccount = $this->resolveExistingAccount($employee, $bank, $iban);
                    $action = $existingAccount === null ? 'create' : 'update';

                    $accountAttributes = [
                        'bank_id' => $bank?->id,
                        'iban' => $iban,
                        'account_name' => $accountName,
                        'is_primary' => $parsedRow['is_primary'] ?? ($existingAccount ? $existingAccount->is_primary : true),
                    ];
                }
            }

            $rowResult = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'],
                'bank_name' => $parsedRow['bank_name'],
                'iban' => $parsedRow['iban'],
                'account_name' => $parsedRow['account_name'],
                'is_primary' => $accountAttributes['is_primary'] ?? ($parsedRow['is_primary'] ?? null),
                'action' => $action,
                'errors' => $rowErrors,
                'warnings' => [],
                'employee' => $employee,
                'existing_account' => $existingAccount,
                'account_attributes' => $accountAttributes,
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
     * @param  array<string, mixed>  $parsedRow
     */
    private function hasAccountData(array $parsedRow): bool
    {
        foreach ([
            'bank_name',
            'iban',
            'account_name',
            'is_primary',
        ] as $field) {
            if ($parsedRow[$field] !== null && $parsedRow[$field] !== '') {
                return true;
            }
        }

        return false;
    }

    private function resolveExistingAccount(
        Employee $employee,
        ?Bank $bank,
        ?string $iban,
    ): ?EmployeeBankAccount {
        $query = EmployeeBankAccount::query()->where('employee_id', $employee->id);

        if ($iban !== null) {
            $match = (clone $query)->where('iban', $iban)->first();
            if ($match) {
                return $match;
            }
        }

        if ($bank !== null) {
            $match = (clone $query)->where('bank_id', $bank->id)->first();
            if ($match) {
                return $match;
            }
        }

        return (clone $query)->where('is_primary', true)->first() ?? (clone $query)->first();
    }
}
