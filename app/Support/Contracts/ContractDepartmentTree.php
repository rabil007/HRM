<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ContractDepartmentTree
{
    public const CONTEXT_INDEX = 'index';

    public const CONTEXT_NO_CONTRACT = 'no-contract';

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
        string $context,
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
                $query->whereHas('contracts', function (Builder $contractQuery) use ($companyId): void {
                    $contractQuery->where('company_id', $companyId);
                });
            },
            self::CONTEXT_NO_CONTRACT => function (Builder $query): void {
                $query->whereDoesntHave('contracts');
            },
            default => throw new InvalidArgumentException("Unknown contract department tree context: {$context}"),
        };
    }
}
