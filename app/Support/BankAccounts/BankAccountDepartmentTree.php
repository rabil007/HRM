<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeVisibilityScope;
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
        ?User $user = null,
    ): array {
        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $filters,
            self::employeeScope($companyId, $context, $user),
        );
    }

    /**
     * @return callable(Builder<Employee>): void
     */
    private static function employeeScope(int $companyId, string $context, ?User $user): callable
    {
        return match ($context) {
            self::CONTEXT_INDEX => function (Builder $query) use ($companyId, $user): void {
                $query->whereHas('bankAccounts', function (Builder $bankAccountQuery) use ($companyId): void {
                    $bankAccountQuery->where('company_id', $companyId);
                });

                if ($user !== null) {
                    EmployeeVisibilityScope::apply($query, $user, $companyId);
                }
            },
            self::CONTEXT_NO_ACCOUNT => function (Builder $query) use ($companyId, $user): void {
                $query->whereDoesntHave('bankAccounts');

                if ($user !== null) {
                    EmployeeVisibilityScope::apply($query, $user, $companyId);
                }
            },
            default => throw new InvalidArgumentException("Unknown bank account department tree context: {$context}"),
        };
    }
}
