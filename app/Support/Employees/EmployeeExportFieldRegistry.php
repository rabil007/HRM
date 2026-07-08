<?php

namespace App\Support\Employees;

use App\Imports\EmployeesImport;
use App\Models\User;

final class EmployeeExportFieldRegistry
{
    /** UAE dirham fixed peg: 1 USD = 3.6725 AED */
    public const AED_PER_USD = 3.6725;

    /**
     * @var list<string>
     */
    public const DEFAULT_FIELD_KEYS = [
        'id',
        'employee_no',
        'name',
        'branch',
        'department',
        'position',
        'manager',
        'work_email',
        'phone',
        'status',
        'hire_date',
        'created_at',
    ];

    /**
     * @return array<string, array{label: string, group: string, permission: string|null}>
     */
    public static function definitions(): array
    {
        return [
            'id' => ['label' => 'ID', 'group' => 'employee', 'permission' => null],
            'employee_no' => ['label' => 'Employee No', 'group' => 'employee', 'permission' => null],
            'name' => ['label' => 'Name', 'group' => 'employee', 'permission' => null],
            'branch' => ['label' => 'Branch', 'group' => 'employee', 'permission' => null],
            'department' => ['label' => 'Department', 'group' => 'employee', 'permission' => null],
            'position' => ['label' => 'Position', 'group' => 'employee', 'permission' => null],
            'rank' => ['label' => 'Rank', 'group' => 'employee', 'permission' => null],
            'project' => ['label' => 'Project', 'group' => 'employee', 'permission' => null],
            'client' => ['label' => 'Client', 'group' => 'employee', 'permission' => null],
            'manager' => ['label' => 'Manager', 'group' => 'employee', 'permission' => null],
            'work_email' => ['label' => 'Work Email', 'group' => 'employee', 'permission' => null],
            'personal_email' => ['label' => 'Personal Email', 'group' => 'employee', 'permission' => null],
            'phone' => ['label' => 'Phone', 'group' => 'employee', 'permission' => null],
            'phone_home_country' => ['label' => 'Home Country Phone', 'group' => 'employee', 'permission' => null],
            'date_of_birth' => ['label' => 'Date of Birth', 'group' => 'employee', 'permission' => null],
            'hire_date' => ['label' => 'Date of Hire', 'group' => 'employee', 'permission' => null],
            'place_of_birth' => ['label' => 'Place of Birth', 'group' => 'employee', 'permission' => null],
            'marital_status' => ['label' => 'Marital Status', 'group' => 'employee', 'permission' => null],
            'spouse_name' => ['label' => 'Spouse Name', 'group' => 'employee', 'permission' => null],
            'address' => ['label' => 'Address', 'group' => 'employee', 'permission' => null],
            'nearest_airport' => ['label' => 'Nearest Airport', 'group' => 'employee', 'permission' => null],
            'emergency_contact' => ['label' => 'Emergency Contact', 'group' => 'employee', 'permission' => null],
            'emergency_phone' => ['label' => 'Emergency Phone', 'group' => 'employee', 'permission' => null],
            'passport_number' => ['label' => 'Passport Number', 'group' => 'employee', 'permission' => EmployeesImport::SENSITIVE_FIELD_PERMISSIONS['passport_number'] ?? null],
            'emirates_id' => ['label' => 'Emirates ID', 'group' => 'employee', 'permission' => EmployeesImport::SENSITIVE_FIELD_PERMISSIONS['emirates_id'] ?? null],
            'gender' => ['label' => 'Gender', 'group' => 'employee', 'permission' => null],
            'religion' => ['label' => 'Religion', 'group' => 'employee', 'permission' => null],
            'nationality' => ['label' => 'Nationality', 'group' => 'employee', 'permission' => null],
            'visa_type' => ['label' => 'Visa Type', 'group' => 'employee', 'permission' => null],
            'company_visa_type' => ['label' => 'Sponsor', 'group' => 'employee', 'permission' => null],
            'salary_payment_method' => ['label' => 'Salary Payment Method', 'group' => 'employee', 'permission' => null],
            'status' => ['label' => 'Status', 'group' => 'employee', 'permission' => null],
            'created_at' => ['label' => 'Created At', 'group' => 'employee', 'permission' => null],
            'contract_payroll_category' => ['label' => 'Contract Payroll Category', 'group' => 'contract', 'permission' => null],
            'contract_salary_structure' => ['label' => 'Contract Salary Structure', 'group' => 'contract', 'permission' => null],
            'contract_start_date' => ['label' => 'Contract Start Date', 'group' => 'contract', 'permission' => null],
            'contract_end_date' => ['label' => 'Contract End Date', 'group' => 'contract', 'permission' => null],
            'contract_labor_contract_id' => ['label' => 'Labor Contract ID', 'group' => 'contract', 'permission' => null],
            'contract_basic_salary' => ['label' => 'Contract Basic Salary', 'group' => 'contract', 'permission' => null],
            'contract_housing_allowance' => ['label' => 'Contract Housing Allowance', 'group' => 'contract', 'permission' => null],
            'contract_transport_allowance' => ['label' => 'Contract Transport Allowance', 'group' => 'contract', 'permission' => null],
            'contract_other_allowances' => ['label' => 'Contract Other Allowances', 'group' => 'contract', 'permission' => null],
            'contract_supplementary_allowance' => ['label' => 'Contract Supplementary Allowance', 'group' => 'contract', 'permission' => null],
            'contract_site_allowance' => ['label' => 'Contract Site Allowance', 'group' => 'contract', 'permission' => null],
            'contract_note' => ['label' => 'Contract Note', 'group' => 'contract', 'permission' => null],
            'contract_status' => ['label' => 'Contract Status', 'group' => 'contract', 'permission' => null],
            'contract_total_compensation_aed' => ['label' => 'Total Compensation (AED)', 'group' => 'contract', 'permission' => null, 'excel_only' => false],
            'contract_total_compensation_usd' => ['label' => 'Total Compensation (USD)', 'group' => 'contract', 'permission' => null, 'excel_only' => true],
            'bank_name' => ['label' => 'Bank Name', 'group' => 'bank_account', 'permission' => null],
            'bank_iban' => ['label' => 'IBAN', 'group' => 'bank_account', 'permission' => null],
            'bank_account_name' => ['label' => 'Account Name', 'group' => 'bank_account', 'permission' => null],
            'bank_is_primary' => ['label' => 'Primary Account', 'group' => 'bank_account', 'permission' => null],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allKeys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return list<array{key: string, label: string, group: string, allowed: bool, excel_only: bool}>
     */
    public static function optionsForUser(?User $user): array
    {
        return collect(self::definitions())
            ->map(function (array $definition, string $key) use ($user): array {
                $permission = $definition['permission'];

                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'group' => $definition['group'],
                    'allowed' => $permission === null || ($user?->can($permission) ?? false),
                    'excel_only' => (bool) ($definition['excel_only'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    public static function labelFor(string $key): string
    {
        return self::definitions()[$key]['label'] ?? $key;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function sanitizeKeys(array $keys, ?User $user): array
    {
        $definitions = self::definitions();

        return collect($keys)
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter(fn (string $key): bool => $key !== '' && isset($definitions[$key]))
            ->unique()
            ->values()
            ->filter(function (string $key) use ($definitions, $user): bool {
                $permission = $definitions[$key]['permission'];

                return $permission === null || ($user?->can($permission) ?? false);
            })
            ->values()
            ->all();
    }
}
