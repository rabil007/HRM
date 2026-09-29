<?php

namespace App\Support\MasterData;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

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
            $project = Project::query()->create($attributes);

            $project->clients()->sync($normalizedClientIds);

            $this->logRelationshipChanges($project, [], $normalizedClientIds);

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

            $beforeClientIds = $this->currentClientIds($locked);

            $locked->fill($attributes);
            $locked->save();

            $locked->clients()->sync($normalizedClientIds);

            $this->logRelationshipChanges($locked, $beforeClientIds, $normalizedClientIds);

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
            if ($normalizedClientIds === []) {
                return $locked;
            }

            $beforeClientIds = $this->currentClientIds($locked);

            $locked->clients()->syncWithoutDetaching($normalizedClientIds);

            $allClientIds = $this->currentClientIds($locked);

            $this->logRelationshipChanges($locked, $beforeClientIds, $allClientIds);

            return $locked->refresh();
        });
    }

    /**
     * Atomically mutate an existing project's attributes (e.g. is_active) and attach missing client(s).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $clientIdsToAttach
     */
    public function updateAndAttach(Project $project, array $attributes, array $clientIdsToAttach): Project
    {
        return DB::transaction(function () use ($project, $attributes, $clientIdsToAttach): Project {
            /** @var Project $locked */
            $locked = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attributes !== []) {
                $locked->fill($attributes);
                $locked->save();
            }

            $normalizedClientIds = $this->normalizeClientIds($clientIdsToAttach);
            if ($normalizedClientIds !== []) {
                $beforeClientIds = $this->currentClientIds($locked);

                $locked->clients()->syncWithoutDetaching($normalizedClientIds);

                $allClientIds = $this->currentClientIds($locked);

                $this->logRelationshipChanges($locked, $beforeClientIds, $allClientIds);
            }

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
     * @return list<int>
     */
    public function currentClientIds(Project $project): array
    {
        return $project->clients()
            ->pluck('clients.id')
            ->map(fn (mixed $id): int => (int) $id)
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

        // Active business requirements across all lifecycle statuses (Draft, Open, On Hold,
        // Completed, Cancelled) retain client_id + project_id and can be reopened or repeated,
        // so they block unlinking. Soft-deleted requirements represent intentional removal
        // from operational history and do not block relationship modifications.
        $requirementClientId = RecruitmentRequirement::query()
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

        // Active business requirements across all lifecycle statuses (Draft, Open, On Hold,
        // Completed, Cancelled) retain client_id + project_id and can be reopened or repeated,
        // so they block unlinking. Soft-deleted requirements represent intentional removal
        // from operational history and do not block relationship modifications.
        $requirementClientId = RecruitmentRequirement::query()
            ->where('project_id', $project->id)
            ->whereNotIn('client_id', $nextClientIds)
            ->value('client_id');

        if ($requirementClientId !== null) {
            $this->throwRemovalException($project, (int) $requirementClientId, 'recruitment requirements are currently using');
        }
    }

    /**
     * @param  list<int>  $beforeClientIds
     * @param  list<int>  $afterClientIds
     */
    private function logRelationshipChanges(Project $project, array $beforeClientIds, array $afterClientIds): void
    {
        $addedClientIds = array_values(array_diff($afterClientIds, $beforeClientIds));
        $removedClientIds = array_values(array_diff($beforeClientIds, $afterClientIds));

        if ($addedClientIds === [] && $removedClientIds === []) {
            return;
        }

        $allClientIds = array_values(array_unique(array_merge($addedClientIds, $removedClientIds)));
        $clientNames = Client::query()
            ->whereIn('id', $allClientIds)
            ->pluck('name', 'id')
            ->all();

        $addedClientNames = array_values(array_filter(array_map(
            fn (int $id): ?string => $clientNames[$id] ?? null,
            $addedClientIds,
        )));

        $removedClientNames = array_values(array_filter(array_map(
            fn (int $id): ?string => $clientNames[$id] ?? null,
            $removedClientIds,
        )));

        $companyId = request()->attributes->get('current_company_id');
        if (! $companyId && auth()->check()) {
            $companyId = auth()->user()->current_company_id ?? null;
        }

        $description = match (true) {
            $addedClientIds !== [] && $removedClientIds !== [] => "Synced client assignments for project '{$project->title}'",
            $addedClientIds !== [] => "Attached client(s) to project '{$project->title}'",
            default => "Removed client(s) from project '{$project->title}'",
        };

        $activity = activity()
            ->performedOn($project)
            ->event('updated')
            ->withProperties([
                'project_id' => (int) $project->id,
                'project_title' => (string) $project->title,
                'added_client_ids' => $addedClientIds,
                'removed_client_ids' => $removedClientIds,
                'before_client_ids' => $beforeClientIds,
                'after_client_ids' => $afterClientIds,
                'added_client_names' => $addedClientNames,
                'removed_client_names' => $removedClientNames,
                'old' => ['client_ids' => $beforeClientIds],
                'attributes' => ['client_ids' => $afterClientIds],
            ]);

        if (auth()->check()) {
            $activity->causedBy(auth()->user());
        }

        if ($companyId) {
            $activity->tap(function (Activity $act) use ($companyId): void {
                $act->company_id = (int) $companyId;
            });
        }

        $activity->log($description);
    }

    private function throwRemovalException(Project $project, int $clientId, string $reason): never
    {
        $clientName = Client::query()->whereKey($clientId)->value('name') ?? "Client #{$clientId}";
        $projectTitle = trim((string) $project->title) !== '' ? (string) $project->title : "Project #{$project->id}";

        throw ValidationException::withMessages([
            'client_ids' => "{$clientName} cannot be removed from {$projectTitle} because {$reason} this Client/Project combination.",
        ]);
    }
}
