<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewDeploymentSummary
{
    /**
     * @return array<string, int>
     */
    public static function forCompany(int $companyId): array
    {
        $query = EmployeeDeployment::query()
            ->where('employee_deployments.company_id', $companyId);

        return self::fromQuery($query);
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     * @return array<string, int>
     */
    public static function fromQuery(Builder $query): array
    {
        /** @var Collection<int, EmployeeDeployment> $deployments */
        $deployments = (clone $query)->get();

        $summary = [
            DeploymentStatus::ON_VESSEL => 0,
            DeploymentStatus::JOIN_STANDBY => 0,
            DeploymentStatus::LEAVE_STANDBY => 0,
            DeploymentStatus::ARRIVED => 0,
            DeploymentStatus::TRAVEL => 0,
            DeploymentStatus::IN_HOME => 0,
            DeploymentStatus::DISEMBARKED => 0,
            DeploymentStatus::UNKNOWN => 0,
        ];

        foreach ($deployments as $deployment) {
            $status = DeploymentStatus::resolve($deployment)['status'];
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        $summary[DeploymentStatus::IN_HOME] = CrewDeploymentLatestRecords::inHomeDeploymentIds($deployments)->count();
        $summary['total'] = $deployments->count();

        return $summary;
    }
}
