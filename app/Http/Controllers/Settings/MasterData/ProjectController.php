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
use App\Support\MasterData\GuardProjectClientChange;
use App\Support\MasterData\MasterDataUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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
            ->with('client:id,name')
            ->orderBy('title')
            ->select(['id', 'client_id', 'title', 'is_active']);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        $page = $this->withMasterDataUsage(
            $this->paginateMasterDataIndex(
                $request,
                $query,
                ['title', 'client.name'],
            ),
            'settings.master-data.projects.delete',
        );

        $page['items'] = collect($page['items'])->map(function (Project $project): array {
            return [
                'id' => $project->id,
                'client_id' => $project->client_id,
                'client_name' => $project->client?->name,
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

    public function store(StoreProjectRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Project::class,
            $data,
            redirect()->route('settings.master-data.projects.index'),
            'title',
        );
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $project->update($request->validated());

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

    public function import(ImportProjectsRequest $request)
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

        $imported = 0;
        $emptyTitles = 0;
        $unknownClients = 0;
        $unknownClientNames = [];
        $blockedReparent = 0;
        $blockedTitles = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

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

            if ($existing instanceof Project
                && GuardProjectClientChange::wouldBreakEmployeeConsistency($existing, (int) $client->id)) {
                $blockedReparent++;
                $blockedTitles[$title] = true;

                continue;
            }

            if ($existing instanceof Project) {
                $existing->update([
                    'client_id' => $client->id,
                    'is_active' => $active,
                ]);
            } else {
                Project::query()->create([
                    'title' => $title,
                    'client_id' => $client->id,
                    'is_active' => $active,
                ]);
            }

            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            $unknownList = implode(', ', array_keys($unknownClientNames));
            $blockedList = implode(', ', array_keys($blockedTitles));

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors([
                    'file' => match (true) {
                        $blockedReparent > 0 && $blockedList !== '' => "No rows were imported. Project(s) cannot change client because employees are assigned: {$blockedList}.",
                        $unknownClients > 0 && $unknownList !== '' => "No rows were imported. Unknown or inactive client(s): {$unknownList}.",
                        $unknownClients > 0 => 'No rows were imported. One or more rows had a missing or unknown client.',
                        $emptyTitles > 0 => "No rows were imported. {$emptyTitles} row(s) had an empty project title.",
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
        if ($blockedReparent > 0) {
            $blockedList = implode(', ', array_keys($blockedTitles));
            $message .= $blockedList !== ''
                ? " Skipped {$blockedReparent} row(s) that cannot change client because employees are assigned: {$blockedList}."
                : " Skipped {$blockedReparent} row(s) that cannot change client because employees are assigned.";
        }

        return redirect()
            ->route('settings.master-data.projects.index')
            ->with('success', $message);
    }
}
