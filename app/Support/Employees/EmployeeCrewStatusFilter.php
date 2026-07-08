<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\CrewDeployments\EmployeeCrewStatusPresenter;

final class EmployeeCrewStatusFilter
{
    public const AVAILABLE = 'available';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::AVAILABLE => 'Available',
            DeploymentStatus::ON_VESSEL => 'On vessel',
            DeploymentStatus::JOIN_STANDBY => 'Join standby',
            DeploymentStatus::LEAVE_STANDBY => 'Leave standby',
            DeploymentStatus::ARRIVED => 'Arrived',
            DeploymentStatus::TRAVEL => 'Travelled',
            DeploymentStatus::DISEMBARKED => 'Disembarked',
            DeploymentStatus::IN_HOME => 'In home',
        ];
    }

    public static function isValid(string $crewStatus): bool
    {
        return array_key_exists($crewStatus, self::options());
    }

    /**
     * @return list<int>
     */
    public static function matchingEmployeeIds(int $companyId, string $crewStatus): array
    {
        if (! self::isValid($crewStatus)) {
            return [];
        }

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->get(['id']);

        if ($employees->isEmpty()) {
            return [];
        }

        $latestDeployments = EmployeeDirectoryCrewStatusData::latestDeploymentsFor($employees, $companyId);

        $matching = [];

        foreach ($employees as $employee) {
            $resolved = EmployeeCrewStatusPresenter::fromDeployment(
                $latestDeployments->get($employee->id),
            );

            if ($resolved['status'] === $crewStatus) {
                $matching[] = $employee->id;
            }
        }

        return $matching;
    }
}
