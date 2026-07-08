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
    public function __construct(
        private readonly Builder $query,
        private readonly string $payrollCategory = '',
    ) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        $headings = [
            'Employee No',
            'Employee Name',
            'Department',
            'Position',
            'Labor Contract ID',
            'Start Date',
            'End Date',
            'Basic Salary',
        ];

        if ($this->payrollCategory !== 'crew') {
            $headings[] = 'Housing Allowance';
            $headings[] = 'Transport Allowance';
        }

        if ($this->payrollCategory !== 'office') {
            $headings[] = 'Supplementary Allowance';
            $headings[] = 'Site Allowance';
        }

        if ($this->payrollCategory !== 'crew') {
            $headings[] = 'Other Allowances';
        }

        $headings[] = 'Total Salary';

        return $headings;
    }

    public function map($contract): array
    {
        $totalSalary = (float) $contract->basic_salary
            + (float) $contract->housing_allowance
            + (float) $contract->transport_allowance
            + (float) $contract->supplementary_allowance
            + (float) $contract->site_allowance
            + (float) $contract->other_allowances;

        $row = [
            $contract->employee?->employee_no,
            $contract->employee?->name,
            $contract->employee?->department?->name,
            $contract->employee?->position?->title,
            $contract->labor_contract_id,
            optional($contract->start_date)->toDateString(),
            optional($contract->end_date)->toDateString(),
            $contract->basic_salary,
        ];

        if ($this->payrollCategory !== 'crew') {
            $row[] = $contract->housing_allowance;
            $row[] = $contract->transport_allowance;
        }

        if ($this->payrollCategory !== 'office') {
            $row[] = $contract->supplementary_allowance;
            $row[] = $contract->site_allowance;
        }

        if ($this->payrollCategory !== 'crew') {
            $row[] = $contract->other_allowances;
        }

        $row[] = number_format($totalSalary, 2, '.', '');

        return $row;
    }
}
