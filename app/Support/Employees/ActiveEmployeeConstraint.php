<?php

namespace App\Support\Employees;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restrict queries to the current company's active workforce.
 *
 * Operational / current workflows must use this (or Employee::active()) so
 * inactive and terminated employees cannot appear in pickers, dashboards, or
 * current crew/manning figures. Historical queries must not call this.
 */
final class ActiveEmployeeConstraint
{
    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function apply(Builder $query, int $companyId): Builder
    {
        return $query
            ->where($query->qualifyColumn('company_id'), $companyId)
            ->active();
    }

    /**
     * Constrain a related-record query to an active employee in the company.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function whereHas(Builder $query, int $companyId, string $relation = 'employee'): Builder
    {
        return $query->whereHas($relation, function (Builder $employee) use ($companyId): void {
            self::apply($employee, $companyId);
        });
    }
}
