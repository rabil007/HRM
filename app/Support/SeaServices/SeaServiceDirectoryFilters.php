<?php

namespace App\Support\SeaServices;

use Illuminate\Http\Request;

final class SeaServiceDirectoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $vesselId = '',
        public readonly string $vesselTypeId = '',
        public readonly string $rankId = '',
        public readonly string $clientId = '',
        public readonly string $active = '',
        public readonly string $startDate = '',
        public readonly string $endDate = '',
        public readonly string $branchId = '',
        public readonly string $departmentId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $active = (string) $request->query('active', '');

        if (in_array($active, ['1', 'true', 'yes', 'active'], true)) {
            $active = '1';
        } else {
            $active = '';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            vesselId: (string) $request->query('vessel_id', ''),
            vesselTypeId: (string) $request->query('vessel_type_id', ''),
            rankId: (string) $request->query('rank_id', ''),
            clientId: (string) $request->query('client_id', ''),
            active: $active,
            startDate: (string) $request->query('start_date', ''),
            endDate: (string) $request->query('end_date', ''),
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

        if ($this->vesselId !== '') {
            $query['vessel_id'] = $this->vesselId;
        }

        if ($this->vesselTypeId !== '') {
            $query['vessel_type_id'] = $this->vesselTypeId;
        }

        if ($this->rankId !== '') {
            $query['rank_id'] = $this->rankId;
        }

        if ($this->clientId !== '') {
            $query['client_id'] = $this->clientId;
        }

        if ($this->active !== '') {
            $query['active'] = $this->active;
        }

        if ($this->startDate !== '') {
            $query['start_date'] = $this->startDate;
        }

        if ($this->endDate !== '') {
            $query['end_date'] = $this->endDate;
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
