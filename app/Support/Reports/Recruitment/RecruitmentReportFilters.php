<?php

namespace App\Support\Reports\Recruitment;

use Illuminate\Http\Request;

final class RecruitmentReportFilters
{
    public function __construct(
        public readonly ?int $requirementId = null,
        public readonly ?int $clientId = null,
        public readonly ?int $projectId = null,
        public readonly ?int $positionId = null,
        public readonly ?int $recruiterId = null,
        public readonly ?string $stage = null,
        public readonly ?string $joiningDateFrom = null,
        public readonly ?string $joiningDateTo = null,
        public readonly ?string $conversionStatus = null,
        public readonly ?string $search = null,
        public readonly string $sort = 'created_at',
        public readonly string $direction = 'desc',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $requirementId = $request->query('requirement_id');
        $clientId = $request->query('client_id');
        $projectId = $request->query('project_id');
        $positionId = $request->query('position_id');
        $recruiterId = $request->query('recruiter_id');
        $stage = $request->query('stage');
        $joiningDateFrom = $request->query('joining_date_from');
        $joiningDateTo = $request->query('joining_date_to');
        $conversionStatus = $request->query('conversion_status');
        $search = $request->query('search');
        $sort = (string) $request->query('sort', 'created_at');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return new self(
            requirementId: filled($requirementId) && is_numeric($requirementId) ? (int) $requirementId : null,
            clientId: filled($clientId) && is_numeric($clientId) ? (int) $clientId : null,
            projectId: filled($projectId) && is_numeric($projectId) ? (int) $projectId : null,
            positionId: filled($positionId) && is_numeric($positionId) ? (int) $positionId : null,
            recruiterId: filled($recruiterId) && is_numeric($recruiterId) ? (int) $recruiterId : null,
            stage: filled($stage) ? (string) $stage : null,
            joiningDateFrom: filled($joiningDateFrom) ? (string) $joiningDateFrom : null,
            joiningDateTo: filled($joiningDateTo) ? (string) $joiningDateTo : null,
            conversionStatus: filled($conversionStatus) && in_array($conversionStatus, ['all', 'converted', 'pending', 'unconverted'], true) ? (string) $conversionStatus : 'all',
            search: filled($search) ? trim((string) $search) : null,
            sort: in_array($sort, ['created_at', 'name', 'actual_joining_date', 'expected_joining_date', 'stage'], true) ? $sort : 'created_at',
            direction: $direction,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requirement_id' => $this->requirementId,
            'client_id' => $this->clientId,
            'project_id' => $this->projectId,
            'position_id' => $this->positionId,
            'recruiter_id' => $this->recruiterId,
            'stage' => $this->stage,
            'joining_date_from' => $this->joiningDateFrom,
            'joining_date_to' => $this->joiningDateTo,
            'conversion_status' => $this->conversionStatus,
            'search' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        $params = [];

        if ($this->requirementId !== null) {
            $params['requirement_id'] = (string) $this->requirementId;
        }
        if ($this->clientId !== null) {
            $params['client_id'] = (string) $this->clientId;
        }
        if ($this->projectId !== null) {
            $params['project_id'] = (string) $this->projectId;
        }
        if ($this->positionId !== null) {
            $params['position_id'] = (string) $this->positionId;
        }
        if ($this->recruiterId !== null) {
            $params['recruiter_id'] = (string) $this->recruiterId;
        }
        if ($this->stage !== null) {
            $params['stage'] = $this->stage;
        }
        if ($this->joiningDateFrom !== null) {
            $params['joining_date_from'] = $this->joiningDateFrom;
        }
        if ($this->joiningDateTo !== null) {
            $params['joining_date_to'] = $this->joiningDateTo;
        }
        if ($this->conversionStatus !== null && $this->conversionStatus !== 'all') {
            $params['conversion_status'] = $this->conversionStatus;
        }
        if ($this->search !== null) {
            $params['search'] = $this->search;
        }
        if ($this->sort !== 'created_at') {
            $params['sort'] = $this->sort;
        }
        if ($this->direction !== 'desc') {
            $params['direction'] = $this->direction;
        }

        return $params;
    }
}
