<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class BulkDocumentSignatureEmployeesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Employee>  $query
     */
    public function __construct(private readonly Builder $query) {}

    /**
     * @return Builder<Employee>
     */
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
            'Name',
            'Department',
            'Position',
            'Email',
        ];
    }

    /**
     * @param  Employee  $employee
     * @return list<mixed>
     */
    public function map($employee): array
    {
        return [
            $employee->employee_no,
            $employee->name,
            $employee->department?->name,
            $employee->position?->title,
            $employee->work_email ?: $employee->personal_email,
        ];
    }
}
