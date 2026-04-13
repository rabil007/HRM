<?php

namespace App\Exports;

use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class DepartmentsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Department>  $query
     */
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Company',
            'Branch',
            'Parent',
            'Manager',
            'Name',
            'Code',
            'Status',
            'Created At',
        ];
    }

    public function map($department): array
    {
        return [
            $department->id,
            $department->company?->name,
            $department->branch?->name,
            $department->parent?->name,
            $department->manager?->name,
            $department->name,
            $department->code,
            $department->status,
            optional($department->created_at)->toDateTimeString(),
        ];
    }
}
