<?php

namespace App\Exports;

use App\Models\EmployeeTraining;
use App\Support\EmployeeTrainings\TrainingExpiry;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class TrainingsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<EmployeeTraining>  $query
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
            'Position',
            'Course',
            'Issue Date',
            'Expiry Date',
            'Expiry Status',
            'Institute Center',
            'Country',
        ];
    }

    public function map($training): array
    {
        return [
            $training->employee?->employee_no,
            $training->employee?->name,
            $training->employee?->department?->name,
            $training->employee?->position?->title,
            $training->course?->name,
            optional($training->issue_date)->toDateString(),
            optional($training->expiry_date)->toDateString(),
            TrainingExpiry::humanLabel($training->expiry_date),
            $training->institute_center,
            $training->country?->name,
        ];
    }
}
