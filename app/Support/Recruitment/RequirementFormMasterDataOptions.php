<?php

namespace App\Support\Recruitment;

use App\Models\Client;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Collection;

final class RequirementFormMasterDataOptions
{
    /**
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    public static function clients(?RecruitmentRequirement $requirement = null): array
    {
        $clients = Client::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Client $client): array => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'is_active' => true,
            ])
            ->keyBy('id');

        if ($requirement?->client_id !== null) {
            self::mergeClientOption($clients, (int) $requirement->client_id);
        }

        return $clients->sortBy('name')->values()->all();
    }

    /**
     * @return list<array{id: int, client_ids: list<int>, title: string, is_active: bool}>
     */
    public static function projects(?RecruitmentRequirement $requirement = null): array
    {
        $projects = Project::query()
            ->where('is_active', true)
            ->with('clients:id')
            ->orderBy('title')
            ->get(['id', 'title', 'is_active'])
            ->mapWithKeys(function (Project $project): array {
                $clientIds = $project->clients
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                return [
                    (int) $project->id => [
                        'id' => (int) $project->id,
                        'client_ids' => $clientIds,
                        'title' => (string) $project->title,
                        'is_active' => true,
                    ],
                ];
            });

        if ($requirement?->project_id !== null) {
            self::mergeProjectOption($projects, (int) $requirement->project_id);
        }

        return $projects->sortBy('title')->values()->all();
    }

    /**
     * @param  Collection<int, array{id: int, name: string, is_active: bool}>  $clients
     */
    private static function mergeClientOption(Collection $clients, int $clientId): void
    {
        if ($clients->has($clientId)) {
            return;
        }

        $client = Client::withTrashed()->find($clientId);

        if ($client === null) {
            return;
        }

        $clients->put($clientId, [
            'id' => (int) $client->id,
            'name' => (string) $client->name,
            'is_active' => (bool) $client->is_active,
        ]);
    }

    /**
     * @param  Collection<int, array{id: int, client_ids: list<int>, title: string, is_active: bool}>  $projects
     */
    private static function mergeProjectOption(Collection $projects, int $projectId): void
    {
        if ($projects->has($projectId)) {
            return;
        }

        $project = Project::withTrashed()
            ->with('clients:id')
            ->find($projectId);

        if ($project === null) {
            return;
        }

        $clientIds = $project->clients
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $projects->put($projectId, [
            'id' => (int) $project->id,
            'client_ids' => $clientIds,
            'title' => (string) $project->title,
            'is_active' => (bool) $project->is_active,
        ]);
    }
}
