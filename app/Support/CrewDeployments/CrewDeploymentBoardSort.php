<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Illuminate\Database\Eloquent\Builder;

final class CrewDeploymentBoardSort
{
    public const DEFAULT_SORT = 'joined_date';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * @var list<string>
     */
    public const SORTABLE = [
        'employee_no',
        'employee_name',
        'rank',
        'nationality',
        'vessel_name',
        'hire_date',
        'arrived_date',
        'standby_from',
        'standby_to',
        'standby_days',
        'joined_date',
        'disembarked_date',
        'travelled_date',
        'total_days',
        'sponsor',
        'client',
        'created_at',
    ];

    /**
     * @return array{0: string, 1: string}
     */
    public static function normalize(?string $sort, ?string $direction): array
    {
        $normalizedSort = in_array($sort, self::SORTABLE, true)
            ? $sort
            : self::DEFAULT_SORT;

        $normalizedDirection = $direction === 'asc' ? 'asc' : 'desc';

        return [$normalizedSort, $normalizedDirection];
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     */
    public static function apply(Builder $query, string $sort, string $direction): void
    {
        $query->select('employee_deployments.*');

        match ($sort) {
            'employee_no' => $query
                ->leftJoin('employees', 'employees.id', '=', 'employee_deployments.employee_id')
                ->orderBy('employees.employee_no', $direction),
            'employee_name' => $query
                ->leftJoin('employees', 'employees.id', '=', 'employee_deployments.employee_id')
                ->orderBy('employees.name', $direction),
            'rank' => $query
                ->leftJoin('ranks', 'ranks.id', '=', 'employee_deployments.rank_id')
                ->orderBy('ranks.name', $direction),
            'nationality' => $query
                ->leftJoin('employees', 'employees.id', '=', 'employee_deployments.employee_id')
                ->leftJoin('countries', 'countries.id', '=', 'employees.nationality_id')
                ->orderBy('countries.name', $direction),
            'sponsor' => $query
                ->leftJoin('company_visa_types', 'company_visa_types.id', '=', 'employee_deployments.company_visa_type_id')
                ->orderBy('company_visa_types.name', $direction),
            'client' => $query
                ->leftJoin('clients', 'clients.id', '=', 'employee_deployments.client_id')
                ->orderBy('clients.name', $direction),
            'standby_days' => self::orderByComputedDays(
                $query,
                'employee_deployments.standby_from',
                'employee_deployments.standby_to',
                $direction,
            ),
            'total_days' => self::orderByComputedDays(
                $query,
                'employee_deployments.joined_date',
                'employee_deployments.disembarked_date',
                $direction,
            ),
            default => $query->orderBy('employee_deployments.'.$sort, $direction),
        };

        if ($sort !== 'joined_date') {
            $query->orderByDesc('employee_deployments.joined_date');
        }

        $query->orderByDesc('employee_deployments.created_at');
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     */
    private static function orderByComputedDays(
        Builder $query,
        string $fromColumn,
        string $toColumn,
        string $direction,
    ): void {
        $query->orderByRaw(
            "CASE WHEN {$fromColumn} IS NULL OR {$toColumn} IS NULL THEN 1 ELSE 0 END",
        );

        $expression = self::dayDiffExpression($query, $fromColumn, $toColumn);

        $query->orderByRaw("{$expression} {$direction}");
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     */
    private static function dayDiffExpression(Builder $query, string $fromColumn, string $toColumn): string
    {
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return "(CAST(julianday({$toColumn}) AS INTEGER) - CAST(julianday({$fromColumn}) AS INTEGER) + 1)";
        }

        return "(DATEDIFF({$toColumn}, {$fromColumn}) + 1)";
    }
}
