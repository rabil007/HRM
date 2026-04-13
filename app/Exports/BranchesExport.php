<?php

namespace App\Exports;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class BranchesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Branch>  $query
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
            'Name',
            'Code',
            'Address',
            'City',
            'Country',
            'Phone',
            'Email',
            'Headquarters',
            'Status',
            'Created At',
        ];
    }

    public function map($branch): array
    {
        return [
            $branch->id,
            $branch->company?->name,
            $branch->name,
            $branch->code,
            $branch->address,
            $branch->city,
            $branch->country,
            $branch->phone,
            $branch->email,
            $branch->is_headquarters ? 'Yes' : 'No',
            $branch->status,
            optional($branch->created_at)->toDateTimeString(),
        ];
    }
}
