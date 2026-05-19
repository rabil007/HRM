<?php

namespace App\Support;

use App\Imports\EmployeesImport;

final class OnboardingTemplateImportFields
{
    /** @var list<string> */
    private static array $bankTemplateKeys = ['bank_id', 'iban', 'account_name'];

    /** @var array<string, string> */
    private const TEMPLATE_KEY_TO_IMPORT = [
        'gender_id' => 'gender',
        'religion_id' => 'religion',
        'nationality_id' => 'nationality',
        'branch_id' => 'branch',
        'department_id' => 'department',
        'position_id' => 'position',
        'manager_id' => 'manager_employee_no',
        'bank_id' => 'bank',
        'first_name' => 'name',
        'last_name' => 'name',
    ];

    /** @var list<string> */
    private const DEFAULT_EMPLOYEE_IMPORT_COLUMNS = [
        'work_email',
        'personal_email',
        'phone',
        'phone_home_country',
        'date_of_birth',
        'place_of_birth',
        'marital_status',
        'spouse_name',
        'spouse_birthdate',
        'dependent_children_count',
        'address',
        'nearest_airport',
        'cv_source',
        'emergency_contact',
        'emergency_phone',
        'emergency_contact_home_country',
        'emergency_phone_home_country',
        'passport_number',
        'emirates_id',
        'labor_card_number',
        'branch',
        'department',
        'position',
        'manager_employee_no',
        'gender',
        'religion',
        'nationality',
    ];

    /** @var list<string> */
    private const DEFAULT_CONTRACT_IMPORT_COLUMNS = [
        'contract_type',
        'start_date',
        'end_date',
        'labor_contract_id',
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowances',
    ];

    /** @var list<string> */
    private const DEFAULT_BANK_IMPORT_COLUMNS = [
        'bank',
        'iban',
        'account_name',
    ];

    /**
     * @param  array<string, mixed>|null  $tasks
     * @return list<string>
     */
    public static function columnsForTasks(?array $tasks): array
    {
        if ($tasks === null || ! is_array($tasks)) {
            return EmployeesImport::TEMPLATE_HEADERS;
        }

        if (($tasks['version'] ?? null) === 2 && isset($tasks['stages']) && is_array($tasks['stages'])) {
            return self::orderedColumns(
                self::columnsFromStages($tasks['stages']),
            );
        }

        return EmployeesImport::TEMPLATE_HEADERS;
    }

    /**
     * @param  array<int|string, mixed>  $stages
     * @return list<string>
     */
    private static function columnsFromStages(array $stages): array
    {
        $employeeTemplateKeys = self::collectGroupTemplateKeys($stages, 'employee_fields', self::$bankTemplateKeys);
        $bankTemplateKeys = self::collectGroupTemplateKeys($stages, 'bank_account_fields', []);
        $contractTemplateKeys = self::collectGroupTemplateKeys($stages, 'contract_fields', []);

        $hadEmployeeFieldKeyEver = collect($stages)->contains(
            fn ($stage) => is_array($stage) && array_key_exists('employee_fields', $stage),
        );
        $hadBankFieldKeyEver = collect($stages)->contains(
            fn ($stage) => is_array($stage) && array_key_exists('bank_account_fields', $stage),
        );
        $hadContractFieldKeyEver = collect($stages)->contains(
            fn ($stage) => is_array($stage) && array_key_exists('contract_fields', $stage),
        );

        $employeeColumns = $hadEmployeeFieldKeyEver
            ? self::mapTemplateKeysToImport($employeeTemplateKeys)
            : self::DEFAULT_EMPLOYEE_IMPORT_COLUMNS;

        $bankColumns = $hadBankFieldKeyEver
            ? self::mapTemplateKeysToImport($bankTemplateKeys)
            : self::DEFAULT_BANK_IMPORT_COLUMNS;

        $contractColumns = $hadContractFieldKeyEver
            ? self::mapTemplateKeysToImport($contractTemplateKeys)
            : self::DEFAULT_CONTRACT_IMPORT_COLUMNS;

        return self::mergeRequired(
            array_merge($employeeColumns, $contractColumns, $bankColumns),
        );
    }

    /**
     * @param  array<int|string, mixed>  $stages
     * @param  list<string>  $excludeKeys
     * @return list<string>
     */
    private static function collectGroupTemplateKeys(array $stages, string $groupKey, array $excludeKeys): array
    {
        $exclude = array_fill_keys($excludeKeys, true);
        $keys = [];

        foreach ($stages as $stage) {
            if (! is_array($stage) || ! array_key_exists($groupKey, $stage)) {
                continue;
            }

            foreach (self::normalizeTemplateKeys($stage[$groupKey]) as $key) {
                if ($key === '' || isset($exclude[$key])) {
                    continue;
                }

                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @return list<string>
     */
    private static function normalizeTemplateKeys(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $keys = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $key = trim($field);

                if ($key !== '') {
                    $keys[] = $key;
                }

                continue;
            }

            if (is_array($field)) {
                $key = trim((string) ($field['key'] ?? ''));

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $templateKeys
     * @return list<string>
     */
    private static function mapTemplateKeysToImport(array $templateKeys): array
    {
        $importFields = array_fill_keys(EmployeesImport::fields(), true);
        $columns = [];

        foreach ($templateKeys as $templateKey) {
            $importColumn = self::TEMPLATE_KEY_TO_IMPORT[$templateKey] ?? $templateKey;

            if ($importColumn === 'image' || $importColumn === 'rank_id') {
                continue;
            }

            if (! isset($importFields[$importColumn])) {
                continue;
            }

            $columns[$importColumn] = true;
        }

        return array_keys($columns);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function mergeRequired(array $columns): array
    {
        foreach (EmployeesImport::REQUIRED_FIELDS as $required) {
            $columns[] = $required;
        }

        $columns[] = 'status';

        return self::orderedColumns($columns);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function orderedColumns(array $columns): array
    {
        $unique = array_fill_keys($columns, true);
        $ordered = [];

        foreach (EmployeesImport::TEMPLATE_HEADERS as $header) {
            if (isset($unique[$header])) {
                $ordered[] = $header;
            }
        }

        return $ordered;
    }
}
