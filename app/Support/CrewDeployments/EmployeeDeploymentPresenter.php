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
            'hire_date' => $deployment->hire_date?->toDateString(),
            'arrived_date' => $deployment->arrived_date?->toDateString(),
            'standby_from' => $deployment->standby_from?->toDateString(),
            'standby_to' => $deployment->standby_to?->toDateString(),
            'standby_days' => DeploymentStatus::standbyDays($deployment),
            'joined_date' => $deployment->joined_date?->toDateString(),
            'disembarked_date' => $deployment->disembarked_date?->toDateString(),
            'travelled_date' => $deployment->travelled_date?->toDateString(),
            'total_days' => DeploymentStatus::totalDays($deployment),
            'remarks' => $deployment->remarks,
            'status' => $status['status'],
            'status_label' => $status['label'],
            'current_vessel' => $status['current_vessel'],
            'created_at' => $deployment->created_at?->toIso8601String(),
        ];
    }
}
