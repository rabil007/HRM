<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class EmployeesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Employee>  $query
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
            'Employee No',
            'First Name',
            'Last Name',
            'Branch',
            'Department',
            'Position',
            'Manager',
            'Work Email',
            'Phone',
            'Status',
            'Start Date',
            'Created At',
        ];
    }

    public function map($employee): array
    {
        $managerName = $employee->manager
            ? trim("{$employee->manager->first_name} {$employee->manager->last_name}")
            : null;

        $startDate = $employee->currentContract?->start_date;

        return [
            $employee->id,
            $employee->employee_no,
            $employee->first_name,
            $employee->last_name,
            $employee->branch?->name,
            $employee->department?->name,
            $employee->position?->title,
            $managerName,
            $employee->work_email,
            $employee->phone,
            $employee->status,
            optional($startDate)->toDateString(),
            optional($employee->created_at)->toDateTimeString(),
        ];
    }
}
