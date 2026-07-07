<?php

namespace App\Support\Contracts;

use App\Enums\PayrollCategory;
use Illuminate\Http\Request;

final class ContractDirectoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $lifecycle = ContractLifecycleFilter::ALL,
        public readonly string $status = '',
        public readonly string $payrollCategory = '',
        public readonly string $salaryStructure = '',
        public readonly string $branchId = '',
        public readonly string $departmentId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $lifecycle = (string) $request->query('lifecycle', ContractLifecycleFilter::ALL);

        if (! ContractLifecycleFilter::isValid($lifecycle)) {
            $lifecycle = ContractLifecycleFilter::ALL;
        }

        $payrollCategory = (string) $request->query('payroll_category', '');

        if ($payrollCategory !== '' && ! in_array($payrollCategory, PayrollCategory::values(), true)) {
            $payrollCategory = '';
        }

        $salaryStructure = (string) $request->query('salary_structure', '');

        if ($salaryStructure !== '' && ! ContractSalaryStructureFilter::isValid($salaryStructure)) {
            $salaryStructure = '';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            lifecycle: $lifecycle,
            status: (string) $request->query('status', ''),
            payrollCategory: $payrollCategory,
            salaryStructure: $salaryStructure,
            branchId: (string) $request->query('branch_id', ''),
            departmentId: (string) $request->query('department_id', ''),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        $query = [];

        if ($this->search !== '') {
            $query['search'] = $this->search;
        }

        if ($this->lifecycle !== ContractLifecycleFilter::ALL) {
            $query['lifecycle'] = $this->lifecycle;
        }

        if ($this->status !== '') {
            $query['status'] = $this->status;
        }

        if ($this->payrollCategory !== '') {
            $query['payroll_category'] = $this->payrollCategory;
        }

        if ($this->salaryStructure !== '') {
            $query['salary_structure'] = $this->salaryStructure;
        }

        if ($this->branchId !== '') {
            $query['branch_id'] = $this->branchId;
        }

        if ($this->departmentId !== '') {
            $query['department_id'] = $this->departmentId;
        }

        return $query;
    }
}
