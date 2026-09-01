<?php

namespace App\Support\Documents;

use App\Models\Employee;
use InvalidArgumentException;

final class TemplateDesignEmployeePreview
{
    public const SEARCH_LIMIT = 8;

    /**
     * @return list<array{id: int, name: string, employee_no: string|null}>
     */
    public static function search(int $companyId, string $query, int $limit = self::SEARCH_LIMIT): array
    {
        $limit = max(1, min($limit, self::SEARCH_LIMIT));
        $term = trim($query);
        $escaped = addcslashes($term, '%_\\');

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when($escaped !== '', function ($query) use ($escaped): void {
                $query->where(function ($inner) use ($escaped): void {
                    $inner->where('name', 'like', "%{$escaped}%")
                        ->orWhere('employee_no', 'like', "%{$escaped}%");
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'employee_no']);

        return $employees
            ->map(fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no !== null && $employee->employee_no !== ''
                    ? (string) $employee->employee_no
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, employee_no: string|null, values: array<string, string>}
     */
    public static function valuesForCompanyEmployee(int $companyId, Employee $employee): array
    {
        if ((int) $employee->company_id !== $companyId) {
            throw new InvalidArgumentException('Employee does not belong to the expected company.');
        }

        $values = DocumentTemplateMergeFields::valuesForEmployee($employee);
        $allowed = array_fill_keys(DocumentTemplateMergeFields::allowedKeys(), true);
        $safeValues = [];

        foreach ($values as $key => $value) {
            if (isset($allowed[$key])) {
                $safeValues[$key] = (string) $value;
            }
        }

        return [
            'id' => (int) $employee->id,
            'name' => (string) $employee->name,
            'employee_no' => $employee->employee_no !== null && $employee->employee_no !== ''
                ? (string) $employee->employee_no
                : null,
            'values' => $safeValues,
        ];
    }
}
