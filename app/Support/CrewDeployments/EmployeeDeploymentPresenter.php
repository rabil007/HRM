<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;

final class EmployeeDeploymentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeDeployment $deployment): array
    {
        $deployment->loadMissing([
            'employee.nationalityRef',
            'rank',
            'client',
            'companyVisaType',
        ]);

        $status = DeploymentStatus::resolve($deployment);

        return [
            'id' => $deployment->id,
            'employee_id' => $deployment->employee_id,
            'employee_no' => $deployment->employee?->employee_no,
            'employee_name' => $deployment->employee?->name,
            'nationality' => $deployment->employee?->nationalityRef?->name,
            'rank_id' => $deployment->rank_id,
            'rank_name' => $deployment->rank?->name,
            'client_id' => $deployment->client_id,
            'client_name' => $deployment->client?->name,
            'company_visa_type_id' => $deployment->company_visa_type_id,
            'company_visa_type_name' => $deployment->companyVisaType?->name,
            'vessel_name' => $deployment->vessel_name,
            'hire_date' => $deployment->employee?->hire_date?->toDateString(),
            'arrived_date' => $deployment->arrived_date?->toDateString(),
            'join_standby_from' => $deployment->join_standby_from?->toDateString(),
            'join_standby_to' => $deployment->join_standby_to?->toDateString(),
            'join_standby_days' => DeploymentStatus::joinStandbyDays($deployment),
            'leave_standby_from' => $deployment->leave_standby_from?->toDateString(),
            'leave_standby_to' => $deployment->leave_standby_to?->toDateString(),
            'leave_standby_days' => DeploymentStatus::leaveStandbyDays($deployment),
            'joined_date' => $deployment->joined_date?->toDateString(),
            'disembarked_date' => $deployment->disembarked_date?->toDateString(),
            'travelled_date' => $deployment->travelled_date?->toDateString(),
            'vessel_days' => DeploymentStatus::vesselDays($deployment),
            'remarks' => $deployment->remarks,
            'status' => $status['status'],
            'status_label' => $status['label'],
            'current_vessel' => $status['current_vessel'],
        ];
    }
}
