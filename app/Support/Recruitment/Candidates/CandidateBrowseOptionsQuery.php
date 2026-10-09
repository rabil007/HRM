<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentRequirement;

/**
 * Company-wide filter options for browsing candidates.
 * Includes closed / filled / cancelled parents and all recruiters' requirements.
 * Does not apply create-time ownership or Open-only constraints.
 */
final class CandidateBrowseOptionsQuery
{
    /**
     * @return array{requirements: list<array<string, mixed>>}
     */
    public static function forCompany(int $companyId): array
    {
        $requirements = RecruitmentRequirement::query()
            ->forCompany($companyId)
            ->with([
                'lines' => fn ($q) => $q
                    ->with('position:id,title')
                    ->orderBy('id'),
                'assignedRecruiter:id,name',
                'client:id,name',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(function (RecruitmentRequirement $requirement): array {
                return [
                    'id' => (int) $requirement->id,
                    'requirement_number' => (string) $requirement->requirement_number,
                    'status' => $requirement->status->value,
                    'status_label' => $requirement->status->label(),
                    'client_name' => $requirement->client?->name,
                    'assigned_to' => $requirement->assigned_to,
                    'assigned_to_name' => $requirement->assignedRecruiter?->name,
                    'lines' => $requirement->lines->map(fn ($line): array => [
                        'id' => (int) $line->id,
                        'position_id' => (int) $line->position_id,
                        'position_title' => (string) ($line->position?->title ?? 'Position'),
                        'status' => $line->status->value,
                        'status_label' => $line->status->label(),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'requirements' => $requirements,
        ];
    }
}
