<?php

namespace App\Support\EmployeeProfileTemplates;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

final class EmployeeProfileTemplateCsvImport
{
    /**
     * @param  array<string, string>  $fieldToColumn  Template field key => CSV column key
     * @return list<string>
     */
    public static function visibleColumns(Employee $employee, string $table, array $fieldToColumn): array
    {
        $columns = [];

        foreach ($fieldToColumn as $fieldKey => $column) {
            if (EmployeeProfileTemplateRequestRules::isFieldVisible($employee, $table, $fieldKey)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $fieldToColumn
     * @return list<string>
     */
    public static function requiredColumns(Employee $employee, string $table, array $fieldToColumn): array
    {
        $columns = [];

        foreach ($fieldToColumn as $fieldKey => $column) {
            if (
                EmployeeProfileTemplateRequestRules::isFieldVisible($employee, $table, $fieldKey)
                && EmployeeProfileTemplateRequestRules::isFieldRequired($employee, $table, $fieldKey)
            ) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public static function assertImportAvailable(Employee $employee, string $table, array $fieldToColumn): void
    {
        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, $table);

        if (self::visibleColumns($employee, $table, $fieldToColumn) === []) {
            throw ValidationException::withMessages([
                'file' => 'Import is not available for this employee profile template.',
            ]);
        }
    }

    /**
     * @param  array<string, string>  $fieldToColumn
     * @param  array<string, string>  $sampleValuesByColumn
     */
    public static function buildTemplateCsv(
        Employee $employee,
        string $table,
        array $fieldToColumn,
        array $sampleValuesByColumn,
    ): string {
        $columns = self::visibleColumns($employee, $table, $fieldToColumn);
        $header = implode(',', $columns);
        $row = implode(',', array_map(
            fn (string $column): string => $sampleValuesByColumn[$column] ?? '',
            $columns,
        ));

        return $header."\n".$row."\n";
    }

    /**
     * @param  array<string, string>  $fieldToColumn
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $map
     * @return array<string, string|null>
     */
    public static function extractRowValues(
        Employee $employee,
        string $table,
        array $fieldToColumn,
        array $row,
        array $map,
    ): array {
        $values = [];

        foreach ($fieldToColumn as $fieldKey => $column) {
            if (! EmployeeProfileTemplateRequestRules::isFieldVisible($employee, $table, $fieldKey)) {
                continue;
            }

            if (! array_key_exists($column, $map)) {
                $values[$fieldKey] = null;

                continue;
            }

            $raw = trim((string) ($row[$map[$column]] ?? ''));

            $values[$fieldKey] = $raw === '' ? null : $raw;
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $rowValues
     */
    public static function rowIsEmpty(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $keys
     */
    public static function hasMeaningfulContent(array $attributes, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $attributes[$key] ?? null;

            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
