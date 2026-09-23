<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;

final class DuplicateRequirementDto
{
    /**
     * @param  list<int>  $matchingPositionIds
     * @return array{
     *     id: int,
     *     requirement_number: string,
     *     client_id: int,
     *     client_name: string,
     *     project_id: int|null,
     *     project_title: string|null,
     *     status: string,
     *     status_label: string,
     *     required_by_date: string,
     *     required_by_date_formatted: string,
     *     total_headcount: int,
     *     matching_positions: list<string>,
     *     positions: list<array{
     *         position_id: int,
     *         position_title: string,
     *         required_headcount: int,
     *         status: string,
     *         status_label: string
     *     }>
     * }
     */
    public static function fromRequirement(RecruitmentRequirement $requirement, array $matchingPositionIds = []): array
    {
        $matchingTitles = [];
        $positions = [];

        foreach ($requirement->lines as $line) {
            /** @var RecruitmentRequirementLine $line */
            $positionId = (int) $line->position_id;
            $title = (string) ($line->position?->title ?? "Position #{$positionId}");

            if (in_array($positionId, $matchingPositionIds, true)) {
                $matchingTitles[] = $title;
            }

            $positions[] = [
                'position_id' => $positionId,
                'position_title' => $title,
                'required_headcount' => (int) $line->required_headcount,
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
            ];
        }

        // If no matchingPositionIds were specified, consider all lines matching
        if ($matchingPositionIds === []) {
            $matchingTitles = array_column($positions, 'position_title');
        }

        return [
            'id' => (int) $requirement->id,
            'requirement_number' => (string) $requirement->requirement_number,
            'client_id' => (int) $requirement->client_id,
            'client_name' => (string) ($requirement->client?->name ?? '—'),
            'project_id' => $requirement->project_id !== null ? (int) $requirement->project_id : null,
            'project_title' => $requirement->project?->title,
            'status' => $requirement->status->value,
            'status_label' => $requirement->status->label(),
            'required_by_date' => $requirement->required_by_date?->format('Y-m-d') ?? '',
            'required_by_date_formatted' => $requirement->required_by_date?->format('d-m-Y') ?? '—',
            'total_headcount' => (int) $requirement->lines->sum('required_headcount'),
            'matching_positions' => array_values(array_unique($matchingTitles)),
            'positions' => $positions,
        ];
    }
}
