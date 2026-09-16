<?php

namespace App\Exports;

use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class PositionsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Position>  $query
     */
    public function __construct(
        private readonly Builder $query,
        private readonly ?string $companyName,
    ) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Company',
            'Department',
            'Title',
            'Description',
            'Grade',
            'Min Salary',
            'Max Salary',
            'Status',
            'Attachment',
            'Created At',
        ];
    }

    public function map($position): array
    {
        return [
            $position->id,
            $this->companyName,
            $position->department?->name,
            $position->title,
            $position->description,
            $position->grade,
            $position->min_salary,
            $position->max_salary,
            $position->status,
            $position->attachment_original_name,
            optional($position->created_at)->toDateTimeString(),
        ];
    }
}
