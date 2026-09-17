<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\ImportClientsRequest;
use App\Http\Requests\Settings\MasterData\StoreClientRequest;
use App\Http\Requests\Settings\MasterData\UpdateClientRequest;
use App\Models\Client;
use App\Support\Clients\ClientShowQuery;
use App\Support\MasterData\MasterDataUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ClientController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index(): InertiaResponse
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $user = request()->user();
        $canViewProjects = (bool) ($user?->can('settings.master-data.projects.view'));
        $canViewVessels = (bool) ($user?->can('crew_operations.vessels.view'));

        $query = Client::query()
            ->orderBy('name')
            ->select(['id', 'name', 'is_active']);

        $withCount = [];
        if ($canViewProjects) {
            $withCount[] = 'projects';
        }
        if ($canViewVessels) {
            $withCount['vessels'] = fn ($vesselQuery) => $companyId > 0
                ? $vesselQuery->where('company_id', $companyId)
                : $vesselQuery->whereRaw('1 = 0');
        }

        if (! empty($withCount)) {
            $query->withCount($withCount);
        }

        $page = $this->withMasterDataUsage(
            $this->paginateMasterDataIndex(
                request(),
                $query,
                ['name'],
            ),
            'settings.master-data.clients.delete',
        );

        $page['items'] = collect($page['items'])->map(function (Client $client) use ($canViewProjects, $canViewVessels): array {
            return [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'is_active' => (bool) $client->is_active,
                'projects_count' => $canViewProjects ? (int) ($client->projects_count ?? 0) : null,
                'vessels_count' => $canViewVessels ? (int) ($client->vessels_count ?? 0) : null,
                'is_in_use' => (bool) $client->getAttribute('is_in_use'),
                'can_delete' => (bool) $client->getAttribute('can_delete'),
                'usage_count' => $client->getAttribute('usage_count') !== null ? (int) $client->getAttribute('usage_count') : null,
                'usage_label' => $client->getAttribute('usage_label'),
            ];
        })->all();

        return Inertia::render('settings/master-data/clients', [
            'clients' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
            'can' => [
                'view_projects' => $canViewProjects,
                'view_vessels' => $canViewVessels,
            ],
        ]);
    }

    public function show(Request $request, Client $client): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $overview = ClientShowQuery::get($client, $companyId, $request->user());

        return Inertia::render('settings/master-data/client-show', [
            'client' => $overview['client'],
            'operations' => $overview['operations'],
            'can' => $overview['can'],
            'recent_activity' => $overview['recent_activity'],
            'can_view_audit' => $overview['can_view_audit'],
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Client::class,
            $data,
            redirect()->route('settings.master-data.clients.index'),
        );
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->safe()->only(['name', 'is_active']));

        if ($request->boolean('redirect_to_show') || str_contains((string) $request->header('referer'), "/settings/master-data/clients/{$client->id}")) {
            return redirect()->route('settings.master-data.clients.show', $client)->with('success', 'Client updated successfully.');
        }

        return redirect()->route('settings.master-data.clients.index');
    }

    public function destroy(Client $client): RedirectResponse
    {
        if ($blocked = MasterDataUsage::denyDeleteRedirect($client, 'settings.master-data.clients.index')) {
            return $blocked;
        }

        $client->delete();

        return redirect()->route('settings.master-data.clients.index');
    }

    public function importTemplate(): Response
    {
        $csv = "name,is_active\nCharter Co A,yes\nShip Management B,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="clients-import-template.csv"',
        ]);
    }

    public function import(ImportClientsRequest $request): RedirectResponse
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.clients.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.clients.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['name', 'client', 'client name', 'company', 'company name'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.clients.index')
                ->withErrors(['file' => 'The CSV must include a name column.']);
        }

        $imported = 0;
        $emptyNames = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($name === '') {
                $emptyNames++;

                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            Client::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => $active],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            return redirect()
                ->route('settings.master-data.clients.index')
                ->withErrors([
                    'file' => $emptyNames > 0
                        ? "No rows were imported. {$emptyNames} row(s) had an empty name."
                        : 'No rows were imported. Ensure each row has a name.',
                ]);
        }

        return redirect()
            ->route('settings.master-data.clients.index')
            ->with('success', "Imported {$imported} client row(s).");
    }
}
