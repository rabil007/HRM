<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class UsersExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<User>  $query
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
            'Role',
            'Name',
            'Email',
            'Status',
            'Last Login At',
            'Created At',
        ];
    }

    public function map($user): array
    {
        return [
            $user->id,
            $user->company?->name,
            $user->role?->name,
            $user->name,
            $user->email,
            $user->status,
            optional($user->last_login_at)->toDateTimeString(),
            optional($user->created_at)->toDateTimeString(),
        ];
    }
}
