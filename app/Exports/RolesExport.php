<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Spatie\Permission\Models\Role;

class RolesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Role>  $query
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
            'Name',
            'Permissions',
            'Created At',
        ];
    }

    public function map($role): array
    {
        return [
            $role->id,
            $role->name,
            $role->permissions()->pluck('name')->implode(', '),
            optional($role->created_at)->toDateTimeString(),
        ];
    }
}
