<?php

namespace App\Support\MasterData;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SyncProjectClients
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $clientIds
     */
    public function create(array $attributes, array $clientIds): Project
    {
        return DB::transaction(function () use ($attributes, $clientIds): Project {
            $normalizedClientIds = $this->normalizeClientIds($clientIds);

            /** @var Project $project */
            $project = Project::query()->create([
                ...$attributes,
                'client_id' => $normalizedClientIds[0] ?? null,
            ]);

            $project->clients()->sync($normalizedClientIds);

            return $project->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $clientIds
     */
    public function update(Project $project, array $attributes, array $clientIds): Project
    {
        return DB::transaction(function () use ($project, $attributes, $clientIds): Project {
            /** @var Project $locked */
            $locked = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedClientIds = $this->normalizeClientIds($clientIds);

            $this->assertReferencedPairsRemainCovered($locked, $normalizedClientIds);
            $this->assertRemovalsAreSafe($locked, $normalizedClientIds);

            $locked->fill($attributes);
            $locked->client_id = $this->legacyClientIdFor($locked, $normalizedClientIds);
            $locked->save();

            $locked->clients()->sync($normalizedClientIds);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<int|string>  $clientIds
     */
    public function attach(Project $project, array $clientIds): Project
    {
        return DB::transaction(function () use ($project, $clientIds): Project {
            /** @var Project $locked */
            $locked = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedClientIds = $this->normalizeClientIds($clientIds);
            $locked->clients()->syncWithoutDetaching($normalizedClientIds);

            $allClientIds = $locked->clients()
                ->pluck('clients.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $locked->client_id = $this->legacyClientIdFor($locked, $allClientIds);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  list<int|string>  $clientIds
     * @return list<int>
     */
    public function normalizeClientIds(array $clientIds): array
    {
        return collect($clientIds)
            ->filter(fn (mixed $id): bool => $id !== null && $id !== '')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $nextClientIds
     */
    private function assertRemovalsAreSafe(Project $project, array $nextClientIds): void
    {
        $currentClientIds = $this->currentClientIds($project);
        $removedClientIds = array_values(array_diff($currentClientIds, $nextClientIds));

        if ($removedClientIds === []) {
            return;
        }

        $employeeClientId = Employee::query()
            ->where('project_id', $project->id)
            ->whereIn('client_id', $removedClientIds)
            ->value('client_id');

        if ($employeeClientId !== null) {
            $this->throwRemovalException($project, (int) $employeeClientId, 'employees are currently using');
        }

        $requirementClientId = RecruitmentRequirement::query()
            ->withTrashed()
            ->where('project_id', $project->id)
            ->whereIn('client_id', $removedClientIds)
            ->value('client_id');

        if ($requirementClientId !== null) {
            $this->throwRemovalException($project, (int) $requirementClientId, 'recruitment requirements are currently using');
        }
    }

    /**
     * @param  list<int>  $nextClientIds
     */
    private function assertReferencedPairsRemainCovered(Project $project, array $nextClientIds): void
    {
        if ($nextClientIds === []) {
            return;
        }

        $employeeClientId = Employee::query()
            ->where('project_id', $project->id)
            ->whereNotNull('client_id')
            ->whereNotIn('client_id', $nextClientIds)
            ->value('client_id');

        if ($employeeClientId !== null) {
            $this->throwRemovalException($project, (int) $employeeClientId, 'employees are currently using');
        }

        $requirementClientId = RecruitmentRequirement::query()
            ->withTrashed()
            ->where('project_id', $project->id)
            ->whereNotIn('client_id', $nextClientIds)
            ->value('client_id');

        if ($requirementClientId !== null) {
            $this->throwRemovalException($project, (int) $requirementClientId, 'recruitment requirements are currently using');
        }
    }

    /**
     * @return list<int>
     */
    private function currentClientIds(Project $project): array
    {
        $clientIds = $project->clients()
            ->pluck('clients.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($clientIds === [] && $project->client_id !== null) {
            $clientIds[] = (int) $project->client_id;
        }

        return collect($clientIds)->unique()->sort()->values()->all();
    }

    /**
     * @param  list<int>  $clientIds
     */
    private function legacyClientIdFor(Project $project, array $clientIds): ?int
    {
        $existingClientId = $project->client_id !== null ? (int) $project->client_id : null;

        if ($existingClientId !== null && in_array($existingClientId, $clientIds, true)) {
            return $existingClientId;
        }

        // Transitional compatibility for legacy `projects.client_id`.
        // The `client_project` pivot is authoritative and this column is removed later.
        return $clientIds[0] ?? null;
    }

    private function throwRemovalException(Project $project, int $clientId, string $reason): never
    {
        $clientName = Client::query()->whereKey($clientId)->value('name') ?? "Client #{$clientId}";
        $projectTitle = trim((string) $project->title) !== '' ? (string) $project->title : "Project #{$project->id}";

        throw ValidationException::withMessages([
            'client_ids' => "{$clientName} cannot be removed from {$projectTitle} because {$reason} this Client/Project combination.",
            'client_id' => "{$clientName} cannot be removed from {$projectTitle} because {$reason} this Client/Project combination.",
        ]);
    }
}
