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
        public readonly string $managerId = '',
        public readonly string $genderId = '',
        public readonly string $nationalityId = '',
        public readonly string $visaTypeId = '',
        public readonly string $companyVisaTypeId = '',
        public readonly string $rankId = '',
        public readonly string $approvalLocationId = '',
        public readonly string $sssaOptionId = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            branchId: trim((string) $request->query('branch_id', '')),
            departmentId: trim((string) $request->query('department_id', '')),
            positionId: trim((string) $request->query('position_id', '')),
            status: trim((string) $request->query('status', '')),
            managerId: trim((string) $request->query('manager_id', '')),
            genderId: trim((string) $request->query('gender_id', '')),
            nationalityId: trim((string) $request->query('nationality_id', '')),
            visaTypeId: trim((string) $request->query('visa_type_id', '')),
            companyVisaTypeId: trim((string) $request->query('company_visa_type_id', '')),
            rankId: trim((string) $request->query('rank_id', '')),
            approvalLocationId: trim((string) $request->query('approval_location_id', '')),
            sssaOptionId: trim((string) $request->query('sssa_option_id', '')),
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

        if ($this->managerId !== '') {
            $query['manager_id'] = $this->managerId;
        }

        if ($this->genderId !== '') {
            $query['gender_id'] = $this->genderId;
        }

        if ($this->nationalityId !== '') {
            $query['nationality_id'] = $this->nationalityId;
        }

        if ($this->visaTypeId !== '') {
            $query['visa_type_id'] = $this->visaTypeId;
        }

        if ($this->companyVisaTypeId !== '') {
            $query['company_visa_type_id'] = $this->companyVisaTypeId;
        }

        if ($this->rankId !== '') {
            $query['rank_id'] = $this->rankId;
        }

        if ($this->approvalLocationId !== '') {
            $query['approval_location_id'] = $this->approvalLocationId;
        }

        if ($this->sssaOptionId !== '') {
            $query['sssa_option_id'] = $this->sssaOptionId;
        }

        return $query;
    }
}
