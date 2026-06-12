<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CrewDeploymentLatestRecords
{
    /**
     * @param  Collection<int, EmployeeDeployment>  $deployments
     * @return Collection<int, EmployeeDeployment>
     */
    public static function latestByEmployee(Collection $deployments): Collection
    {
        return $deployments
            ->groupBy('employee_id')
            ->map(function (Collection $group): EmployeeDeployment {
                return $group
                    ->sortBy([
                        ['sort_order', 'desc'],
                        ['id', 'desc'],
                    ])
                    ->first();
            })
            ->values();
    }

    /**
     * @param  Collection<int, EmployeeDeployment>  $deployments
     * @return Collection<int, int>
     */
    public static function inHomeDeploymentIds(Collection $deployments, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        return self::latestByEmployee($deployments)
            ->filter(fn (EmployeeDeployment $deployment): bool => DeploymentStatus::isInHome($deployment, $today))
            ->pluck('id');
    }
}
