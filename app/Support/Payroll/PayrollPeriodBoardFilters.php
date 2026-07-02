<?php

namespace App\Support\Payroll;

use Illuminate\Http\Request;

final class PayrollPeriodBoardFilters
{
    public function __construct(
        public readonly string $departmentId = '',
        public readonly string $positionId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
        );
    }

    public function isActive(): bool
    {
        return $this->departmentId !== '' || $this->positionId !== '';
    }
}
