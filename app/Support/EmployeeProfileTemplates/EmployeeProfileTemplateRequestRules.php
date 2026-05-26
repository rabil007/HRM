<?php

namespace App\Support\EmployeeProfileTemplates;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EmployeeProfileTemplateRequestRules
{
    /** @var array<string, list<string>> */
    public const DEFAULT_REQUIRED_BY_TABLE = [
        'employees' => ['employee_no', 'name'],
        'employee_contracts' => ['contract_type', 'start_date', 'status'],
        'employee_bank_accounts' => [],
        'employee_education_qualifications' => ['certificate'],
        'employee_work_experiences' => ['company_name', 'job_title', 'date_from'],
        'employee_languages' => ['language_name'],
        'employee_trainings' => ['course_id', 'issue_date', 'institute_center'],
        'employee_sea_services' => [
            'vessel_type_id',
            'vessel_name',
            'rank_id',
            'start_date',
            'end_date',
        ],
        'employee_vaccinations' => ['vaccination_name'],
        'employee_documents' => ['document_type_id'],
    ];

    /** @var array<string, string> */
    private const TABLE_TO_TAB = [
        'employees' => 'personal',
        'employee_contracts' => 'contract',
        'employee_bank_accounts' => 'bank',
        'employee_education_qualifications' => 'education',
        'employee_work_experiences' => 'work_experience',
        'employee_languages' => 'languages',
        'employee_trainings' => 'training',
        'employee_sea_services' => 'sea_service',
        'employee_documents' => 'documents',
        'employee_vaccinations' => 'vaccinations',
    ];

    /**
     * @return array{
     *     version: int,
     *     tabs: array<string, array{visible: bool}>,
     *     fields: array<string, array<string, array{visible: bool, required: bool}>>,
     *     personal_field_keys: list<string>,
     *     employee_tabs: array<string, bool|list<string>|null>
     * }
     */
    public static function resolved(Employee $employee): array
    {
        if ($employee->relationLoaded('employeeProfileTemplate')) {
            return EmployeeProfileTemplateResolver::resolve($employee->getRelation('employeeProfileTemplate'));
        }

        $employee->loadMissing('employeeProfileTemplate');

        return EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);
    }

    public static function isFieldVisible(Employee $employee, string $table, string $fieldKey): bool
    {
        $resolved = self::resolved($employee);
        $fieldConfig = $resolved['fields'][$table][$fieldKey] ?? null;

        if (! is_array($fieldConfig)) {
            return true;
        }

        return ($fieldConfig['visible'] ?? false) === true;
    }

    public static function isFieldRequired(Employee $employee, string $table, string $fieldKey): bool
    {
        if (! self::isFieldVisible($employee, $table, $fieldKey)) {
            return false;
        }

        $resolved = self::resolved($employee);
        $fieldConfig = $resolved['fields'][$table][$fieldKey] ?? null;

        if (! is_array($fieldConfig)) {
            return in_array($fieldKey, self::DEFAULT_REQUIRED_BY_TABLE[$table] ?? [], true);
        }

        return (bool) ($fieldConfig['required'] ?? false);
    }

    public static function assertTabForTable(Employee $employee, string $table): void
    {
        $tab = self::TABLE_TO_TAB[$table] ?? null;

        if ($tab === null) {
            return;
        }

        $resolved = self::resolved($employee);

        if ($tab === 'personal') {
            return;
        }

        $visible = (bool) ($resolved['tabs'][$tab]['visible'] ?? false);

        if (! $visible) {
            throw ValidationException::withMessages([
                '_' => 'This section is not enabled for this employee profile template.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $baseRules
     * @return array<string, mixed>
     */
    public static function applyToRules(Employee $employee, string $table, array $baseRules): array
    {
        $resolved = self::resolved($employee);
        $tableFields = $resolved['fields'][$table] ?? [];

        foreach ($baseRules as $fieldKey => $rules) {
            if (! is_string($fieldKey) || ! array_key_exists($fieldKey, $tableFields)) {
                continue;
            }

            $baseRules[$fieldKey] = self::rulesForField(
                $employee,
                $table,
                $fieldKey,
                is_array($rules) ? $rules : [$rules],
            );
        }

        return $baseRules;
    }

    /**
     * @param  array<string, mixed>  $baseRules
     * @return array<string, mixed>
     */
    public static function applyToWildcardRules(
        Employee $employee,
        string $table,
        array $baseRules,
        string $keyPrefix,
    ): array {
        $resolved = self::resolved($employee);
        $tableFields = $resolved['fields'][$table] ?? [];

        foreach ($baseRules as $key => $rules) {
            if (! is_string($key) || ! str_starts_with($key, $keyPrefix)) {
                continue;
            }

            $fieldKey = substr($key, strlen($keyPrefix));

            if (! array_key_exists($fieldKey, $tableFields)) {
                continue;
            }

            $baseRules[$key] = self::rulesForField(
                $employee,
                $table,
                $fieldKey,
                is_array($rules) ? $rules : [$rules],
            );
        }

        return $baseRules;
    }

    /**
     * @param  array<string, mixed>  $baseRules
     * @return array<string, mixed>
     */
    public static function validate(
        Request $request,
        Employee $employee,
        string $table,
        array $baseRules,
    ): array {
        self::assertTabForTable($employee, $table);

        $rules = self::applyToRules($employee, $table, $baseRules);

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function hasValidated(array $validated, string $key): bool
    {
        return array_key_exists($key, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function persistedValue(array $validated, string $key, mixed $fallback): mixed
    {
        return array_key_exists($key, $validated) ? $validated[$key] : $fallback;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function onlyVisibleAttributes(Employee $employee, string $table, array $validated): array
    {
        $resolved = self::resolved($employee);
        $tableFields = $resolved['fields'][$table] ?? [];

        foreach (array_keys($validated) as $fieldKey) {
            if (! array_key_exists($fieldKey, $tableFields)) {
                continue;
            }

            if (! self::isFieldVisible($employee, $table, $fieldKey)) {
                unset($validated[$fieldKey]);
            }
        }

        return $validated;
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private static function rulesForField(Employee $employee, string $table, string $fieldKey, array $rules): array
    {
        if (! self::isFieldVisible($employee, $table, $fieldKey)) {
            return ['prohibited'];
        }

        if (self::isFieldRequired($employee, $table, $fieldKey)) {
            return self::rulesAsRequired($rules);
        }

        return self::rulesAsOptional($rules);
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private static function rulesAsRequired(array $rules): array
    {
        $filtered = [];
        $hasRequired = false;

        foreach ($rules as $rule) {
            if ($rule === 'nullable' || $rule === 'sometimes') {
                continue;
            }

            if ($rule === 'required') {
                $hasRequired = true;
            }

            $filtered[] = $rule;
        }

        if (! $hasRequired) {
            array_unshift($filtered, 'required');
        }

        return $filtered;
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private static function rulesAsOptional(array $rules): array
    {
        $filtered = [];
        $hasNullable = false;
        $hasSometimes = false;

        foreach ($rules as $rule) {
            if ($rule === 'required') {
                continue;
            }

            if ($rule === 'nullable') {
                $hasNullable = true;
            }

            if ($rule === 'sometimes') {
                $hasSometimes = true;
            }

            $filtered[] = $rule;
        }

        if (! $hasNullable && ! $hasSometimes) {
            array_unshift($filtered, 'nullable');
        }

        return $filtered;
    }
}
