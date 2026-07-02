<?php

namespace App\Support\Payroll;

use App\Enums\PayrollBoardEmployeeGroup;
use Illuminate\Http\Request;

final class PayrollPeriodBoardFilters
{
    public function __construct(
        public readonly string $departmentId = '',
        public readonly string $positionId = '',
        public readonly PayrollBoardEmployeeGroup $employeeGroup = PayrollBoardEmployeeGroup::Total,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
            employeeGroup: PayrollBoardEmployeeGroup::fromQuery($request->query('employee_group')),
        );
    }

    public function isActive(): bool
    {
        return $this->departmentId !== '' || $this->positionId !== '';
    }
}
