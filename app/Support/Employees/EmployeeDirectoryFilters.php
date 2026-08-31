<?php

namespace App\Support\Employees;

use Illuminate\Http\Request;

final class EmployeeDirectoryFilters
{
    public const STATUS_ALL = 'all';

    /** @var list<string> */
    public const STATUSES = [
        'active',
        'inactive',
        'on_leave',
        'terminated',
        self::STATUS_ALL,
    ];

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
        public readonly string $projectId = '',
        public readonly string $approvalLocationId = '',
        public readonly string $sssaOptionId = '',
        public readonly string $crewStatus = '',
        public readonly string $roleId = '',
        public readonly string $missingFields = '',
        public readonly string $presentFields = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        [$missing, $present] = self::completenessFromInput(
            $data['missing_fields'] ?? '',
            $data['present_fields'] ?? '',
            $data['emirates_id_presence'] ?? '',
        );

        return new self(
            search: trim((string) ($data['search'] ?? '')),
            branchId: trim((string) ($data['branch_id'] ?? '')),
            departmentId: trim((string) ($data['department_id'] ?? '')),
            positionId: trim((string) ($data['position_id'] ?? '')),
            status: self::normalizeStatus($data['status'] ?? ''),
            managerId: trim((string) ($data['manager_id'] ?? '')),
            genderId: trim((string) ($data['gender_id'] ?? '')),
            nationalityId: trim((string) ($data['nationality_id'] ?? '')),
            visaTypeId: trim((string) ($data['visa_type_id'] ?? '')),
            companyVisaTypeId: trim((string) ($data['company_visa_type_id'] ?? '')),
            rankId: trim((string) ($data['rank_id'] ?? '')),
            projectId: trim((string) ($data['project_id'] ?? '')),
            approvalLocationId: trim((string) ($data['approval_location_id'] ?? '')),
            sssaOptionId: trim((string) ($data['sssa_option_id'] ?? '')),
            crewStatus: trim((string) ($data['crew_status'] ?? '')),
            roleId: trim((string) ($data['role_id'] ?? '')),
            missingFields: $missing,
            presentFields: $present,
        );
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->all());
    }

    public function appliesDefaultActiveStatus(): bool
    {
        return $this->status === '';
    }

    public function omitsHrStatusPredicate(): bool
    {
        return $this->status === self::STATUS_ALL;
    }

    public function hasInvalidStatus(): bool
    {
        return $this->status !== ''
            && $this->status !== self::STATUS_ALL
            && ! in_array($this->status, self::STATUSES, true);
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

        if ($this->projectId !== '') {
            $query['project_id'] = $this->projectId;
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

        if ($this->missingFields !== '') {
            $query[EmployeeDirectoryCompleteness::MISSING_QUERY_KEY] = $this->missingFields;
        }

        if ($this->presentFields !== '') {
            $query[EmployeeDirectoryCompleteness::PRESENT_QUERY_KEY] = $this->presentFields;
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public function toInertiaFilters(): array
    {
        return [
            'branch_id' => $this->branchId,
            'department_id' => $this->departmentId,
            'position_id' => $this->positionId,
            'status' => $this->status,
            'manager_id' => $this->managerId,
            'gender_id' => $this->genderId,
            'nationality_id' => $this->nationalityId,
            'visa_type_id' => $this->visaTypeId,
            'company_visa_type_id' => $this->companyVisaTypeId,
            'rank_id' => $this->rankId,
            'project_id' => $this->projectId,
            'approval_location_id' => $this->approvalLocationId,
            'sssa_option_id' => $this->sssaOptionId,
            'crew_status' => $this->crewStatus,
            'role_id' => $this->roleId,
            'missing_fields' => $this->missingFields,
            'present_fields' => $this->presentFields,
        ];
    }

    private static function normalizeStatus(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function completenessFromInput(mixed $missing, mixed $present, mixed $legacyEmiratesIdPresence): array
    {
        $missingParsed = EmployeeDirectoryCompleteness::parse($missing);
        $presentParsed = EmployeeDirectoryCompleteness::parse($present);

        $missingKeys = $missingParsed['valid'] ? $missingParsed['keys'] : ['_invalid'];
        $presentKeys = $presentParsed['valid'] ? $presentParsed['keys'] : ['_invalid'];

        $legacy = strtolower(trim((string) $legacyEmiratesIdPresence));

        if ($legacy === 'missing' && ! in_array('emirates_id', $missingKeys, true)) {
            $missingKeys[] = 'emirates_id';
        }

        if ($legacy === 'present' && ! in_array('emirates_id', $presentKeys, true)) {
            $presentKeys[] = 'emirates_id';
        }

        if ($legacy !== '' && $legacy !== 'missing' && $legacy !== 'present') {
            $missingKeys[] = '_invalid';
        }

        $missingCsv = in_array('_invalid', $missingKeys, true)
            ? '_invalid'
            : EmployeeDirectoryCompleteness::toCsv($missingKeys);
        $presentCsv = in_array('_invalid', $presentKeys, true)
            ? '_invalid'
            : EmployeeDirectoryCompleteness::toCsv($presentKeys);

        return [$missingCsv, $presentCsv];
    }
}
