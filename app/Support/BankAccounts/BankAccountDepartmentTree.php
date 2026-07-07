<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class BankAccountDepartmentTree
{
    public const CONTEXT_INDEX = 'index';

    public const CONTEXT_NO_ACCOUNT = 'no-account';

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
                $query->whereHas('bankAccounts', function (Builder $bankAccountQuery) use ($companyId): void {
                    $bankAccountQuery->where('company_id', $companyId);
                });
            },
            self::CONTEXT_NO_ACCOUNT => function (Builder $query): void {
                $query->whereDoesntHave('bankAccounts');
            },
            default => throw new InvalidArgumentException("Unknown bank account department tree context: {$context}"),
        };
    }
}
