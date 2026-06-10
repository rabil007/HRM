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
            'Hire Date',
            'Arrived Date',
            'Standby From',
            'Standby To',
            'Standby Days',
            'Joined Date',
            'Disembarked Date',
            'Travelled Date',
            'Total Days',
            'Company Visa Type',
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
        $deployment->loadMissing(['employee.nationalityRef', 'rank', 'client', 'companyVisaType']);
        $status = DeploymentStatus::resolve($deployment);

        return [
            $deployment->employee?->employee_no,
            $deployment->employee?->name,
            $deployment->rank?->name,
            $deployment->employee?->nationalityRef?->name,
            $status['label'],
            $deployment->vessel_name,
            $deployment->hire_date?->format('Y-m-d'),
            $deployment->arrived_date?->format('Y-m-d'),
            $deployment->standby_from?->format('Y-m-d'),
            $deployment->standby_to?->format('Y-m-d'),
            DeploymentStatus::standbyDays($deployment),
            $deployment->joined_date?->format('Y-m-d'),
            $deployment->disembarked_date?->format('Y-m-d'),
            $deployment->travelled_date?->format('Y-m-d'),
            DeploymentStatus::totalDays($deployment),
            $deployment->companyVisaType?->name,
            $deployment->client?->name,
            $deployment->remarks,
        ];
    }
}
