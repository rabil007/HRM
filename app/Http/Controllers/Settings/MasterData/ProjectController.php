<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\ImportProjectsRequest;
use App\Http\Requests\Settings\MasterData\StoreProjectRequest;
use App\Http\Requests\Settings\MasterData\UpdateProjectRequest;
use App\Models\Client;
use App\Models\Project;
use App\Support\MasterData\MasterDataQuickCreate;
use App\Support\MasterData\MasterDataUsage;
use App\Support\MasterData\SyncProjectClients;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class ProjectController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index()
    {
        $request = request();
        $clientId = $request->query('client_id');
        $clientId = $clientId !== null && $clientId !== '' ? (int) $clientId : null;

        $query = Project::query()
            ->with('clients:id,name,is_active')
            ->orderBy('title')
            ->select(['id', 'title', 'is_active']);

        if ($clientId !== null) {
            $query->whereHas('clients', fn ($clientQuery) => $clientQuery->whereKey($clientId));
        }

        $page = $this->withMasterDataUsage(
            $this->paginateMasterDataIndex(
                $request,
                $query,
                ['title', 'clients.name'],
            ),
            'settings.master-data.projects.delete',
        );

        $page['items'] = collect($page['items'])->map(function (Project $project): array {
            return [
                'id' => $project->id,
                'client_ids' => $project->clients
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all(),
                'clients' => $project->clients
                    ->map(fn (Client $client): array => [
                        'id' => (int) $client->id,
                        'name' => (string) $client->name,
                        'is_active' => (bool) $client->is_active,
                    ])
                    ->values()
                    ->all(),
                'title' => $project->title,
                'is_active' => (bool) $project->is_active,
                'is_in_use' => (bool) $project->getAttribute('is_in_use'),
                'can_delete' => (bool) $project->getAttribute('can_delete'),
                'usage_count' => $project->getAttribute('usage_count'),
                'usage_label' => $project->getAttribute('usage_label'),
            ];
        })->all();

        return Inertia::render('settings/master-data/projects', [
            'projects' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
            'filters' => [
                'client_id' => $clientId,
            ],
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'is_active' => (bool) $client->is_active,
                ])
                ->all(),
        ]);
    }

    public function store(StoreProjectRequest $request, SyncProjectClients $syncProjectClients): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $clientIds = $data['client_ids'];
        $attributes = Arr::only($data, ['title', 'is_active']);
        $attributes['is_active'] = $attributes['is_active'] ?? true;

        $title = (string) $attributes['title'];
        $canAttachClients = (bool) $request->user()?->can('settings.master-data.projects.update');
        $existing = MasterDataQuickCreate::findProjectByNormalizedTitle($title);

        if ($existing instanceof Project) {
            return $this->storeRedirectOrQuickCreateJson(
                $request,
                $this->resolveExistingProjectQuickCreate(
                    $existing,
                    $clientIds,
                    $canAttachClients,
                    $syncProjectClients,
                ),
                redirect()->route('settings.master-data.projects.index'),
                'title',
            );
        }

        try {
            $project = $syncProjectClients->create($attributes, $clientIds);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = MasterDataQuickCreate::findProjectByNormalizedTitle($title);

            if (! $existing instanceof Project) {
                throw $exception;
            }

            $project = $this->resolveExistingProjectQuickCreate(
                $existing,
                $clientIds,
                $canAttachClients,
                $syncProjectClients,
            );
        }

        return $this->storeRedirectOrQuickCreateJson(
            $request,
            $project,
            redirect()->route('settings.master-data.projects.index'),
            'title',
        );
    }

    /**
     * @param  list<int>  $clientIds
     */
    private function resolveExistingProjectQuickCreate(
        Project $existing,
        array $clientIds,
        bool $canAttachClients,
        SyncProjectClients $syncProjectClients,
    ): Project {
        MasterDataQuickCreate::failProjectMatch($existing);

        $normalizedClientIds = $syncProjectClients->normalizeClientIds($clientIds);
        $existingClientIds = $syncProjectClients->currentClientIds($existing);
        $missingClientIds = array_values(array_diff($normalizedClientIds, $existingClientIds));

        if ($missingClientIds === []) {
            return $existing;
        }

        if (! $canAttachClients) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $syncProjectClients->attach($existing, $clientIds);

        return $existing->fresh() ?? $existing;
    }

    public function update(UpdateProjectRequest $request, Project $project, SyncProjectClients $syncProjectClients)
    {
        $data = $request->validated();
        $syncProjectClients->update(
            $project,
            Arr::only($data, ['title', 'is_active']),
            $data['client_ids'] ?? [],
        );

        return redirect()->route('settings.master-data.projects.index');
    }

    public function destroy(Project $project)
    {
        if ($blocked = MasterDataUsage::denyDeleteRedirect($project, 'settings.master-data.projects.index')) {
            return $blocked;
        }

        $project->delete();

        return redirect()->route('settings.master-data.projects.index');
    }

    public function importTemplate(): Response
    {
        $csv = "client,project,is_active\nADNOC,Upper Zakum,yes\nADNOC,Das Island,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="projects-import-template.csv"',
        ]);
    }

    public function import(ImportProjectsRequest $request, SyncProjectClients $syncProjectClients)
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['title', 'name', 'project'], true)) {
                $map['title'] = (int) $index;
            }
            if (in_array($key, ['client', 'client_name'], true)) {
                $map['client'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['title'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'The CSV must include a project/title column.']);
        }

        if (! isset($map['client'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'The CSV must include a client column.']);
        }

        $clientsByName = Client::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->keyBy(fn (Client $client): string => mb_strtolower(trim($client->name)));

        $canUpdate = (bool) $request->user()?->can('settings.master-data.projects.update');

        $imported = 0;
        $emptyTitles = 0;
        $unknownClients = 0;
        $unknownClientNames = [];
        $permissionDenied = 0;
        $permissionDeniedTitles = [];
        $failedRows = 0;

        $rawRows = [];
        $statusByTitle = [];
        $displayTitleByNormalized = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $rawRows[] = $row;
            if (count($rawRows) > 2000) {
                break;
            }

            $title = trim((string) ($row[$map['title']] ?? ''));
            if ($title === '') {
                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            $normalizedTitle = mb_strtolower($title);
            $statusByTitle[$normalizedTitle][] = $active;
            if (! isset($displayTitleByNormalized[$normalizedTitle])) {
                $displayTitleByNormalized[$normalizedTitle] = $title;
            }
        }

        fclose($handle);

        foreach ($statusByTitle as $normalizedTitle => $statuses) {
            if (count(array_unique($statuses, SORT_REGULAR)) > 1) {
                $conflictingTitle = $displayTitleByNormalized[$normalizedTitle] ?? $normalizedTitle;

                return redirect()
                    ->route('settings.master-data.projects.index')
                    ->withErrors(['file' => "Project \"{$conflictingTitle}\" has conflicting is_active values in the import file. Use the same status for every row of the same project."]);
            }
        }

        foreach ($rawRows as $row) {
            $title = trim((string) ($row[$map['title']] ?? ''));
            if ($title === '') {
                $emptyTitles++;

                continue;
            }

            $clientName = trim((string) ($row[$map['client']] ?? ''));
            if ($clientName === '') {
                $unknownClients++;

                continue;
            }

            $client = $clientsByName->get(mb_strtolower($clientName));
            if ($client === null) {
                $unknownClients++;
                $unknownClientNames[$clientName] = true;

                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            $existing = Project::query()->where('title', $title)->first();
            try {
                if ($existing instanceof Project) {
                    $currentClientIds = $syncProjectClients->currentClientIds($existing);
                    $needsClientAttach = ! in_array((int) $client->id, $currentClientIds, true);
                    $needsActiveChange = (bool) $existing->is_active !== (bool) $active;

                    if (! $needsClientAttach && ! $needsActiveChange) {
                        $imported++;

                        continue;
                    }

                    if (! $canUpdate) {
                        $permissionDenied++;
                        $permissionDeniedTitles[$title] = true;

                        continue;
                    }

                    $attributes = $needsActiveChange ? ['is_active' => $active] : [];
                    $clientsToAttach = $needsClientAttach ? [(int) $client->id] : [];

                    $syncProjectClients->updateAndAttach($existing, $attributes, $clientsToAttach);
                } else {
                    $syncProjectClients->create([
                        'title' => $title,
                        'is_active' => $active,
                    ], [(int) $client->id]);
                }

                $imported++;
            } catch (\Throwable) {
                $failedRows++;
            }
        }

        if ($imported === 0) {
            $unknownList = implode(', ', array_keys($unknownClientNames));
            $permissionDeniedList = implode(', ', array_keys($permissionDeniedTitles));

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors([
                    'file' => match (true) {
                        $permissionDenied > 0 && $permissionDeniedList !== '' => "No rows were imported. Updating existing project(s) or attaching new clients requires update permission: {$permissionDeniedList}.",
                        $unknownClients > 0 && $unknownList !== '' => "No rows were imported. Unknown or inactive client(s): {$unknownList}.",
                        $unknownClients > 0 => 'No rows were imported. One or more rows had a missing or unknown client.',
                        $emptyTitles > 0 => "No rows were imported. {$emptyTitles} row(s) had an empty project title.",
                        $failedRows > 0 => 'No rows were imported due to processing errors.',
                        default => 'No rows were imported. Ensure each row has a client and project title.',
                    },
                ]);
        }

        $message = "Imported {$imported} project row(s).";
        if ($unknownClients > 0) {
            $unknownList = implode(', ', array_keys($unknownClientNames));
            $message .= $unknownList !== ''
                ? " Skipped {$unknownClients} row(s) with unknown/inactive client(s): {$unknownList}."
                : " Skipped {$unknownClients} row(s) with missing or unknown clients.";
        }
        if ($permissionDenied > 0) {
            $permissionDeniedList = implode(', ', array_keys($permissionDeniedTitles));
            $message .= $permissionDeniedList !== ''
                ? " Skipped {$permissionDenied} row(s) requiring update permission to mutate project or attach new client: {$permissionDeniedList}."
                : " Skipped {$permissionDenied} row(s) requiring update permission.";
        }
        if ($failedRows > 0) {
            $message .= " Skipped {$failedRows} row(s) due to processing errors.";
        }

        return redirect()
            ->route('settings.master-data.projects.index')
            ->with('success', $message);
    }
}
