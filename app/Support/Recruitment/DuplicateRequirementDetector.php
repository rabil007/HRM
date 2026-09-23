<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Database\Eloquent\Collection;

final class DuplicateRequirementDetector
{
    /**
     * Detect active or on-hold requirements matching company, client, project, and one or more positions.
     *
     * @param  list<int>  $positionIds
     * @return Collection<int, RecruitmentRequirement>
     */
    public static function findSimilar(
        int $companyId,
        int $clientId,
        ?int $projectId,
        array $positionIds,
        ?int $excludeRequirementId = null,
    ): Collection {
        if ($positionIds === []) {
            return new Collection;
        }

        $query = RecruitmentRequirement::query()
            ->where('company_id', $companyId)
            ->where('client_id', $clientId)
            ->whereIn('status', [
                RequirementStatus::Draft,
                RequirementStatus::Open,
                RequirementStatus::OnHold,
            ])
            ->whereHas('lines', function ($lineQuery) use ($positionIds): void {
                $lineQuery->whereIn('position_id', $positionIds);
            })
            ->with([
                'client:id,name',
                'project:id,title',
                'lines.position:id,title',
            ]);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        } else {
            $query->whereNull('project_id');
        }

        if ($excludeRequirementId !== null) {
            $query->where('id', '!=', $excludeRequirementId);
        }

        return $query->latest('id')->get();
    }

    /**
     * @param  list<int>  $positionIds
     * @return list<array<string, mixed>>
     */
    public static function findSimilarDtos(
        int $companyId,
        int $clientId,
        ?int $projectId,
        array $positionIds,
        ?int $excludeRequirementId = null,
    ): array {
        $collection = self::findSimilar($companyId, $clientId, $projectId, $positionIds, $excludeRequirementId);

        return $collection->map(fn (RecruitmentRequirement $r): array => DuplicateRequirementDto::fromRequirement($r, $positionIds))->values()->all();
    }
}
