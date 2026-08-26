<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentRequirement;

final class DocumentRequirementPresenter
{
    /**
     * @return array{
     *     is_required: bool,
     *     required_for_all: bool,
     *     department_ids: list<int>,
     *     position_ids: list<int>,
     *     rank_ids: list<int>,
     *     require_issue_date: bool,
     *     require_expiry_date: bool,
     *     require_document_number: bool,
     *     label: string
     * }
     */
    public static function toArray(?DocumentRequirement $requirement): array
    {
        if ($requirement === null) {
            return [
                'is_required' => false,
                'required_for_all' => false,
                'department_ids' => [],
                'position_ids' => [],
                'rank_ids' => [],
                'require_issue_date' => false,
                'require_expiry_date' => false,
                'require_document_number' => false,
                'label' => 'Optional',
            ];
        }

        $departmentIds = $requirement->relationLoaded('departments')
            ? $requirement->departments->pluck('id')
            : $requirement->departments()->pluck('departments.id');
        $positionIds = $requirement->relationLoaded('positions')
            ? $requirement->positions->pluck('id')
            : $requirement->positions()->pluck('positions.id');
        $rankIds = $requirement->relationLoaded('ranks')
            ? $requirement->ranks->pluck('id')
            : $requirement->ranks()->pluck('ranks.id');

        return [
            'is_required' => (bool) $requirement->is_active,
            'required_for_all' => (bool) $requirement->required_for_all,
            'department_ids' => $departmentIds->map(fn ($id): int => (int) $id)->values()->all(),
            'position_ids' => $positionIds->map(fn ($id): int => (int) $id)->values()->all(),
            'rank_ids' => $rankIds->map(fn ($id): int => (int) $id)->values()->all(),
            'require_issue_date' => (bool) $requirement->require_issue_date,
            'require_expiry_date' => (bool) $requirement->require_expiry_date,
            'require_document_number' => (bool) $requirement->require_document_number,
            'label' => DocumentRequirementSummary::label($requirement),
        ];
    }
}
