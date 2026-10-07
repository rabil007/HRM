<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\DB;

/**
 * Batch-resolved lookups so index readiness does not query per row.
 */
final class RequirementSubmissionReadinessLookup
{
    /**
     * @param  array<int, bool>  $eligibleApproverById
     * @param  array<int, list<int>>  $projectClientIds
     */
    public function __construct(
        public readonly array $eligibleApproverById = [],
        public readonly array $projectClientIds = [],
    ) {}

    /**
     * @param  iterable<int, RecruitmentRequirement>  $requirements
     */
    public static function forRequirements(iterable $requirements, int $companyId): self
    {
        $assignedIds = [];
        $projectIdsNeedingClients = [];

        foreach ($requirements as $requirement) {
            if (! in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Returned], true)) {
                continue;
            }

            if ($requirement->assigned_to !== null) {
                $assignedIds[] = (int) $requirement->assigned_to;
            }

            $projectId = $requirement->project_id !== null ? (int) $requirement->project_id : 0;
            if ($projectId > 0 && $requirement->client_id !== null) {
                $projectLoaded = $requirement->relationLoaded('project')
                    && $requirement->project !== null
                    && $requirement->project->relationLoaded('clients');

                if (! $projectLoaded) {
                    $projectIdsNeedingClients[] = $projectId;
                }
            }
        }

        $assignedIds = array_values(array_unique($assignedIds));
        $eligibleIds = RecruiterOptionsQuery::eligibleApproverIdsAmong($companyId, $assignedIds);
        $eligibleSet = array_fill_keys($eligibleIds, true);
        $eligibleApproverById = [];
        foreach ($assignedIds as $assignedId) {
            $eligibleApproverById[$assignedId] = isset($eligibleSet[$assignedId]);
        }

        $projectClientIds = [];
        foreach ($requirements as $requirement) {
            $projectId = $requirement->project_id !== null ? (int) $requirement->project_id : 0;
            if ($projectId <= 0 || isset($projectClientIds[$projectId])) {
                continue;
            }

            if (
                $requirement->relationLoaded('project')
                && $requirement->project !== null
                && $requirement->project->relationLoaded('clients')
            ) {
                $projectClientIds[$projectId] = $requirement->project->clients
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
            }
        }

        $missingProjectIds = array_values(array_unique(array_diff($projectIdsNeedingClients, array_keys($projectClientIds))));
        if ($missingProjectIds !== []) {
            foreach ($missingProjectIds as $projectId) {
                $projectClientIds[$projectId] = [];
            }

            $rows = DB::table('client_project')
                ->whereIn('project_id', $missingProjectIds)
                ->get(['project_id', 'client_id']);

            foreach ($rows as $row) {
                $projectClientIds[(int) $row->project_id][] = (int) $row->client_id;
            }
        }

        return new self($eligibleApproverById, $projectClientIds);
    }

    public function isEligibleApprover(?int $userId, int $companyId): bool
    {
        if ($userId === null) {
            return false;
        }

        if (array_key_exists($userId, $this->eligibleApproverById)) {
            return $this->eligibleApproverById[$userId];
        }

        return RecruiterOptionsQuery::isEligibleApprover($userId, $companyId);
    }

    /**
     * @return list<int>|null
     */
    public function clientIdsForProject(?int $projectId): ?array
    {
        if ($projectId === null || $projectId <= 0) {
            return null;
        }

        return $this->projectClientIds[$projectId] ?? null;
    }

    /**
     * @param  Collection<int, RecruitmentRequirement>|iterable<int, RecruitmentRequirement>  $requirements
     * @return array<string, mixed>
     */
    public static function eagerLoad(): array
    {
        return [
            'client' => fn ($query) => $query->withTrashed()->select('id', 'name', 'is_active', 'deleted_at'),
            'project' => fn ($query) => $query->withTrashed()->select('id', 'title', 'is_active', 'deleted_at'),
            'project.clients:id',
            'assignedRecruiter:id,name',
            'pendingDeadlineExtension.requestedBy:id,name',
            'pendingDeadlineExtension.decidedBy:id,name',
            'repeatedFrom:id,requirement_number',
            'lines.position' => fn ($query) => $query->withTrashed()->select('id', 'title', 'company_id', 'status', 'deleted_at'),
        ];
    }
}
