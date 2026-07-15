<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class DocumentDepartmentTree
{
    public const CONTEXT_INDEX = 'index';

    /**
     * @return list<array{
     *     id: int|null,
     *     name: string,
     *     count: int,
     *     children: list<mixed>,
     *     positions: list<array{id: int, name: string, count: int}>
     * }>
     */
    public static function for(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        string $context = self::CONTEXT_INDEX,
    ): array {
        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $filters,
            self::employeeScope($companyId, $context),
        );
    }

    /**
     * @return callable(Builder<Employee>): void
     */
    private static function employeeScope(int $companyId, string $context): callable
    {
        return match ($context) {
            self::CONTEXT_INDEX => function (Builder $query) use ($companyId): void {
                $query->whereHas('documents', function (Builder $documentQuery) use ($companyId): void {
                    $documentQuery->where('company_id', $companyId);
                });
            },
            default => throw new InvalidArgumentException("Unknown document department tree context: {$context}"),
        };
    }
}
