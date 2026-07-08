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
        public readonly string $crewStatus = '',
        public readonly string $roleId = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            search: trim((string) ($data['search'] ?? '')),
            branchId: trim((string) ($data['branch_id'] ?? '')),
            departmentId: trim((string) ($data['department_id'] ?? '')),
            positionId: trim((string) ($data['position_id'] ?? '')),
            status: trim((string) ($data['status'] ?? '')),
            managerId: trim((string) ($data['manager_id'] ?? '')),
            genderId: trim((string) ($data['gender_id'] ?? '')),
            nationalityId: trim((string) ($data['nationality_id'] ?? '')),
            visaTypeId: trim((string) ($data['visa_type_id'] ?? '')),
            companyVisaTypeId: trim((string) ($data['company_visa_type_id'] ?? '')),
            rankId: trim((string) ($data['rank_id'] ?? '')),
            approvalLocationId: trim((string) ($data['approval_location_id'] ?? '')),
            sssaOptionId: trim((string) ($data['sssa_option_id'] ?? '')),
            crewStatus: trim((string) ($data['crew_status'] ?? '')),
            roleId: trim((string) ($data['role_id'] ?? '')),
        );
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->input('search', '')),
            branchId: trim((string) $request->input('branch_id', '')),
            departmentId: trim((string) $request->input('department_id', '')),
            positionId: trim((string) $request->input('position_id', '')),
            status: trim((string) $request->input('status', '')),
            managerId: trim((string) $request->input('manager_id', '')),
            genderId: trim((string) $request->input('gender_id', '')),
            nationalityId: trim((string) $request->input('nationality_id', '')),
            visaTypeId: trim((string) $request->input('visa_type_id', '')),
            companyVisaTypeId: trim((string) $request->input('company_visa_type_id', '')),
            rankId: trim((string) $request->input('rank_id', '')),
            approvalLocationId: trim((string) $request->input('approval_location_id', '')),
            sssaOptionId: trim((string) $request->input('sssa_option_id', '')),
            crewStatus: trim((string) $request->input('crew_status', '')),
            roleId: trim((string) $request->input('role_id', '')),
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

        if ($this->crewStatus !== '') {
            $query['crew_status'] = $this->crewStatus;
        }

        if ($this->roleId !== '') {
            $query['role_id'] = $this->roleId;
        }

        return $query;
    }
}
