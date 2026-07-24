<?php

namespace App\Exports;

use App\Models\EmployeeSeaService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class SeaServicesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<EmployeeSeaService>  $query
     */
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'Employee No',
            'Employee Name',
            'Department',
            'Vessel',
            'Vessel Type',
            'Rank',
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
            $seaService->rank?->name,
            $seaService->client?->name,
            optional($seaService->start_date)->toDateString(),
            optional($seaService->end_date)->toDateString(),
            $seaService->total_months,
            $seaService->total_days,
            $seaService->crew_assignment_phase_id ? 'Yes' : 'No',
        ];
    }
}
