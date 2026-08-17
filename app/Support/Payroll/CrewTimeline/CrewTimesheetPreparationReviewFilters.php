<?php

namespace App\Support\Payroll\CrewTimeline;

use Illuminate\Http\Request;

final class CrewTimesheetPreparationReviewFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $departmentId = '',
        public readonly string $positionId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
        );
    }

    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->departmentId !== ''
            || $this->positionId !== '';
    }
}
