<?php

namespace App\Exports;

use App\Models\LeaveBalance;
use App\Support\Reports\LeaveBalanceReportPresenter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class LeaveBalanceReportExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<LeaveBalance>  $query
     */
    public function __construct(
        private readonly Builder $query,
    ) {}

    /**
     * @param  Builder<LeaveBalance>  $query
     */
    public static function forQuery(Builder $query): self
    {
        return new self($query);
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Employee No',
            'Employee',
            'Department',
            'Employee Status',
            'Leave Type',
            'Leave Category',
            'Year',
            'Base Entitlement',
            'Carried Days',
            'Total Available',
            'Used Days',
            'Pending Days',
            'Remaining Days',
        ];
    }

    /**
     * @param  LeaveBalance  $balance
     * @return list<mixed>
     */
    public function map($balance): array
    {
        $row = LeaveBalanceReportPresenter::toArray($balance);

        return [
            $row['employee']['employee_no'],
            $row['employee']['name'],
            $row['department']['name'] ?? null,
            $row['employee']['status_label'],
            $row['leave_type']['name'],
            $row['leave_type']['category_label'],
            $row['year'],
            $row['base_entitlement'],
            $row['carried_days'],
            $row['total_available'],
            $row['used_days'],
            $row['pending_days'],
            $row['remaining_days'],
        ];
    }
}
