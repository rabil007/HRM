<?php

namespace App\Support\Payroll;

use App\Enums\PayrollBoardEmployeeGroup;
use Illuminate\Http\Request;

final class PayrollPeriodBoardFilters
{
    public function __construct(
        public readonly string $departmentId = '',
        public readonly string $positionId = '',
        public readonly string $companyVisaTypeId = '',
        public readonly PayrollBoardEmployeeGroup $employeeGroup = PayrollBoardEmployeeGroup::Total,
        public readonly string $crewSalaryStructure = 'daily',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $crewSalaryStructure = in_array($request->query('crew_salary_structure'), ['daily', 'monthly'], true)
            ? (string) $request->query('crew_salary_structure')
            : 'daily';

        return new self(
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
            companyVisaTypeId: trim((string) $request->query('company_visa_type_id', '')),
            employeeGroup: PayrollBoardEmployeeGroup::fromQuery($request->query('employee_group')),
            crewSalaryStructure: $crewSalaryStructure,
        );
    }

    public function isActive(): bool
    {
        return $this->departmentId !== ''
            || $this->positionId !== ''
            || $this->companyVisaTypeId !== '';
    }
}
