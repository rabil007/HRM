<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Department;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;

final class DocumentRequirementFormOptions
{
    /**
     * @return array{
     *     departments: list<array{id: int, name: string}>,
     *     positions: list<array{id: int, title: string}>,
     *     ranks: list<array{id: int, name: string}>,
     *     projects: list<array{id: int, title: string}>
     * }
     */
    public static function for(int $companyId): array
    {
        return [
            'departments' => Department::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => (string) $department->name,
                ])
                ->values()
                ->all(),
            'positions' => Position::query()
                ->where('company_id', $companyId)
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Position $position): array => [
                    'id' => $position->id,
                    'title' => (string) $position->title,
                ])
                ->values()
                ->all(),
            'ranks' => Rank::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Rank $rank): array => [
                    'id' => $rank->id,
                    'name' => (string) $rank->name,
                ])
                ->values()
                ->all(),
            'projects' => Project::query()
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'title' => (string) $project->title,
                ])
                ->values()
                ->all(),
        ];
    }
}
