<?php

namespace App\Support\EmployeeTrainings;

use Illuminate\Http\Request;

final class TrainingDirectoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $expiry = 'all',
        public readonly string $branchId = '',
        public readonly string $departmentId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $expiry = (string) $request->query('expiry', 'all');

        if (! TrainingExpiry::isValidFilter($expiry)) {
            $expiry = 'all';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            expiry: $expiry,
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

        if ($this->expiry !== 'all') {
            $query['expiry'] = $this->expiry;
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
