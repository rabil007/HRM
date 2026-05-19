<?php

namespace App\Support\Employees;

use Illuminate\Http\Request;

final class EmployeeDirectoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $branchId = '',
        public readonly string $departmentId = '',
        public readonly string $positionId = '',
        public readonly string $status = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            branchId: trim((string) $request->query('branch_id', '')),
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
            status: trim((string) $request->query('status', '')),
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

        if ($this->branchId !== '') {
            $query['branch_id'] = $this->branchId;
        }

        if ($this->departmentId !== '') {
            $query['department_id'] = $this->departmentId;
        }

        if ($this->positionId !== '') {
            $query['position_id'] = $this->positionId;
        }

        if ($this->status !== '') {
            $query['status'] = $this->status;
        }

        return $query;
    }
}
