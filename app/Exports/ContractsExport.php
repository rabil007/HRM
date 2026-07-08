<?php

namespace App\Exports;

use App\Models\EmployeeContract;
use App\Support\Contracts\ContractSalaryTotals;
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
        $headings[] = 'Total Salary (USD)';

        return $headings;
    }

    public function map($contract): array
    {
        $totalSalary = ContractSalaryTotals::total($contract, $this->payrollCategory);
        $totalSalaryUsd = ContractSalaryTotals::totalUsd($contract, $this->payrollCategory);

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

        $row[] = $totalSalary;
        $row[] = $totalSalaryUsd;

        return $row;
    }
}
