<?php

namespace App\Exports;

use App\Models\EmployeeDeployment;
use App\Support\CrewDeployments\DeploymentStatus;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CrewDeploymentsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, EmployeeDeployment>  $deployments
     */
    public function __construct(private Collection $deployments) {}

    public function collection(): Collection
    {
        return $this->deployments;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Employee No',
            'Name',
            'Rank',
            'Nationality',
            'Status',
            'Vessel',
            'Date of hire',
            'Arrived Date',
            'Join Standby From',
            'Join Standby To',
            'Join Standby Days',
            'Joined Date',
            'Disembarked Date',
            'Vessel Days',
            'Leave Standby From',
            'Leave Standby To',
            'Leave Standby Days',
            'Travelled Date',
            'Sponsor',
            'Client',
            'Remarks',
        ];
    }

    /**
     * @param  EmployeeDeployment  $deployment
     * @return list<mixed>
     */
    public function map($deployment): array
    {
        $deployment->loadMissing(['employee.nationalityRef', 'rank', 'client', 'companyVisaType', 'vessel']);
        $status = DeploymentStatus::resolve($deployment);

        return [
            $deployment->employee?->employee_no,
            $deployment->employee?->name,
            $deployment->rank?->name,
            $deployment->employee?->nationalityRef?->name,
            $status['label'],
            $deployment->vessel?->name,
            $deployment->employee?->hire_date?->format('Y-m-d'),
            $deployment->arrived_date?->format('Y-m-d'),
            $deployment->join_standby_from?->format('Y-m-d'),
            $deployment->join_standby_to?->format('Y-m-d'),
            DeploymentStatus::joinStandbyDays($deployment),
            $deployment->joined_date?->format('Y-m-d'),
            $deployment->disembarked_date?->format('Y-m-d'),
            DeploymentStatus::vesselDays($deployment),
            $deployment->leave_standby_from?->format('Y-m-d'),
            $deployment->leave_standby_to?->format('Y-m-d'),
            DeploymentStatus::leaveStandbyDays($deployment),
            $deployment->travelled_date?->format('Y-m-d'),
            $deployment->companyVisaType?->name,
            $deployment->client?->name,
            $deployment->remarks,
        ];
    }
}
