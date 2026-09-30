<?php

namespace App\Exports;

use App\Models\EmployeeSeaService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class SeaServicesExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Collection<int, EmployeeSeaService>  $seaServices
     */
    public function __construct(private readonly Collection $seaServices) {}

    public function collection(): Collection
    {
        return $this->seaServices;
    }

    public function headings(): array
    {
        return [
            'Employee No',
            'Employee Name',
            'Department',
            'Vessel',
            'Vessel Type',
            'Position',
            'Client',
            'Start Date',
            'End Date',
            'Months',
            'Days',
            'Linked Assignment Phase',
        ];
    }

    public function map($seaService): array
    {
        return [
            $seaService->employee?->employee_no,
            $seaService->employee?->name,
            $seaService->employee?->department?->name,
            $seaService->vessel?->name,
            $seaService->vesselType?->name,
            $seaService->position?->title,
            $seaService->client?->name,
            optional($seaService->start_date)->toDateString(),
            optional($seaService->end_date)->toDateString(),
            $seaService->total_months,
            $seaService->total_days,
            $seaService->crew_assignment_phase_id ? 'Yes' : 'No',
        ];
    }
}
