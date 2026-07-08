<?php

namespace App\Exports;

use App\Models\EmployeeContract;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class ContractsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<EmployeeContract>  $query
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
            'Employee Name',
            'Branch',
            'Department',
            'Position',
            'Profile Template',
            'Labor Contract ID',
            'Status',
            'Payroll Category',
            'Salary Structure',
            'Start Date',
            'End Date',
            'Basic Salary',
            'Housing Allowance',
            'Transport Allowance',
            'Supplementary Allowance',
            'Site Allowance',
            'Other Allowances',
            'Total Salary',
            'Note',
            'Created At',
        ];
    }

    public function map($contract): array
    {
        $totalSalary = (float) $contract->basic_salary
            + (float) $contract->housing_allowance
            + (float) $contract->transport_allowance
            + (float) $contract->supplementary_allowance
            + (float) $contract->site_allowance
            + (float) $contract->other_allowances;

        return [
            $contract->id,
            $contract->employee?->employee_no,
            $contract->employee?->name,
            $contract->employee?->branch?->name,
            $contract->employee?->department?->name,
            $contract->employee?->position?->title,
            $contract->employee?->employeeProfileTemplate?->name,
            $contract->labor_contract_id,
            ucfirst((string) $contract->status),
            $contract->payroll_category?->label(),
            $contract->resolvedSalaryStructure()->label(),
            optional($contract->start_date)->toDateString(),
            optional($contract->end_date)->toDateString(),
            $contract->basic_salary,
            $contract->housing_allowance,
            $contract->transport_allowance,
            $contract->supplementary_allowance,
            $contract->site_allowance,
            $contract->other_allowances,
            number_format($totalSalary, 2, '.', ''),
            $contract->note,
            optional($contract->created_at)->toDateTimeString(),
        ];
    }
}
