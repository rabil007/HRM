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
        ?ContractDirectoryFilters $contractFilters = null,
    ): array {
        $payrollCategory = $contractFilters?->payrollCategory ?? '';
        $limitToRootDepartmentIds = null;

        if ($payrollCategory !== '' && ContractWorkforceDepartmentScope::isValid($payrollCategory)) {
            $limitToRootDepartmentIds = ContractWorkforceDepartmentScope::rootIdsFor(
                $companyId,
                $payrollCategory,
            );
        }

        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $filters,
            self::employeeScope($companyId, $context, $contractFilters),
            $limitToRootDepartmentIds,
        );
    }

    /**
     * @return callable(Builder<Employee>): void
     */
    private static function employeeScope(
        int $companyId,
        string $context,
        ?ContractDirectoryFilters $contractFilters,
    ): callable {
        return match ($context) {
            self::CONTEXT_INDEX => function (Builder $query) use ($companyId, $contractFilters): void {
                if ($contractFilters !== null) {
                    ContractDirectoryEmployeeScope::apply($query, $companyId, $contractFilters);
                }

                $query->whereHas('contracts', function (Builder $contractQuery) use ($companyId, $contractFilters): void {
                    $contractQuery->where('company_id', $companyId);

                    if ($contractFilters === null) {
                        return;
                    }

                    if ($contractFilters->payrollCategory !== '') {
                        $contractQuery->where('payroll_category', $contractFilters->payrollCategory);
                    }

                    if ($contractFilters->salaryStructure !== '') {
                        ContractSalaryStructureFilter::apply($contractQuery, $contractFilters->salaryStructure);
                    }
                });
            },
            self::CONTEXT_NO_CONTRACT => function (Builder $query) use ($companyId, $contractFilters): void {
                $query->whereDoesntHave('contracts');

                if ($contractFilters !== null) {
                    ContractDirectoryEmployeeScope::apply($query, $companyId, $contractFilters);
                }
            },
            default => throw new InvalidArgumentException("Unknown contract department tree context: {$context}"),
        };
    }
}
