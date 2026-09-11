<?php

namespace App\Http\Controllers\Organization;

use App\Enums\RecentItemType;
use App\Enums\VesselManningHealthStatus;
use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Vessel\ImportVesselsRequest;
use App\Http\Requests\Organization\Vessel\StoreVesselRequest;
use App\Http\Requests\Organization\Vessel\UpdateVesselRequest;
use App\Http\Requests\Organization\VesselManning\UpdateVesselManningRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use App\Support\Activity\RecentActivityQuery;
use App\Support\MasterData\MasterDataUsage;
use App\Support\MasterData\MasterDataUsageSummary;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\RecentItems\RecordRecentItem;
use App\Support\VesselManning\SyncVesselManning;
use App\Support\VesselManning\VesselManningHealthQuery;
use App\Support\VesselManning\VesselManningIndexQuery;
use App\Support\VesselManning\VesselManningPagePermissions;
use App\Support\Vessels\StoresVesselCertificate;
use App\Support\Vessels\VesselIndexQuery;
use App\Support\Vessels\VesselPagePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VesselController extends Controller
{
    use ResolvesPerPage;
    use ReturnsQuickCreateJson;

    public function __construct(private StoresVesselCertificate $certificateStore) {}

    public function index(Request $request): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $vesselTypeId = $request->query('vessel_type_id');
        $vesselTypeId = $vesselTypeId !== null && $vesselTypeId !== '' ? (int) $vesselTypeId : null;

        $clientId = $request->query('client_id');
        $clientId = $clientId !== null && $clientId !== '' ? (int) $clientId : null;

        $manning = $request->query('manning');
        $manning = in_array($manning, ['configured', 'pending'], true) ? $manning : null;

        $health = $request->query('health');
        $health = is_string($health) && in_array($health, VesselManningHealthStatus::values(), true)
            ? $health
            : null;

        $user = $request->user();
        $healthByVessel = [];
        $healthVesselIds = null;
        $healthOrderIds = null;

        if ($user?->can('crew_operations.vessel_manning.view')) {
            $healthQuery = new VesselManningHealthQuery;
            $healthByVessel = $healthQuery->compactByVessel($companyId, $user);
            $nameById = Vessel::query()
                ->where('company_id', $companyId)
                ->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
                ->all();
            $healthOrderIds = VesselManningHealthQuery::sortedVesselIds($healthByVessel, $nameById);

            if ($health !== null) {
                $healthVesselIds = array_values(array_filter(
                    $healthOrderIds,
                    fn (int $id): bool => ($healthByVessel[$id]['status'] ?? null) === $health,
                ));
            }
        }

        $paginator = VesselIndexQuery::paginate(
            $companyId,
            $search,
            $vesselTypeId,
            $perPage,
            $manning,
            $healthVesselIds,
            $healthOrderIds,
            $clientId,
        );

        $canDeletePermission = $user?->can('crew_operations.vessels.delete') ?? false;
        $usageById = MasterDataUsage::summariesForIds(
            Vessel::class,
            $paginator->getCollection()->map(fn (Vessel $vessel): int => (int) $vessel->id)->all(),
            $companyId,
        );

        $vessels = $paginator->through(function (Vessel $vessel) use ($healthByVessel, $usageById, $canDeletePermission, $companyId): array {
            $row = VesselIndexQuery::toArray($vessel);
            $row['manning_health'] = $healthByVessel[$vessel->id] ?? null;
            $summary = $usageById[(int) $vessel->id] ?? MasterDataUsageSummary::none();

            return [...$row, ...$summary->flags($canDeletePermission, $companyId)];
        });

        $vesselsWith = (int) VesselManning::query()
            ->where('company_id', $companyId)
            ->distinct('vessel_id')
            ->count('vessel_id');

        $totalFleet = (int) Vessel::query()->where('company_id', $companyId)->count();

        return Inertia::render('organization/vessels/index', [
            'vessels' => $vessels->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'client_id' => $clientId,
                'vessel_type_id' => $vesselTypeId,
                'manning' => $manning,
                'health' => $health,
            ],
            'vessel_types' => $this->vesselTypes(),
            'clients' => $this->clients(),
            'can' => VesselPagePermissions::for($request->user()),
            'stats' => [
                'total' => $totalFleet,
                'vessels_with_manning' => $vesselsWith,
                'vessels_without_manning' => $totalFleet - $vesselsWith,
            ],
        ]);
    }

    public function show(Request $request, Vessel $vessel, RecordRecentItem $recordRecentItem): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $record = VesselIndexQuery::findForCompany($companyId, $vessel);

        abort_unless($record instanceof Vessel, 404);

        $user = $request->user();
        if ($user !== null) {
            $recordRecentItem->handle($user, $companyId, RecentItemType::Vessel, $record->id);
        }

        return Inertia::render('organization/vessels/show', [
            'vessel' => VesselIndexQuery::toArray($record, includeDetails: true),
            'vessel_types' => $this->vesselTypes(),
            'clients' => $this->clients(),
            'summary' => [
                'manning_ranks' => VesselManning::query()
                    ->where('company_id', $companyId)
                    ->where('vessel_id', $record->id)
                    ->count(),
                'total_required' => (int) VesselManning::query()
                    ->where('company_id', $companyId)
                    ->where('vessel_id', $record->id)
                    ->sum('required_count'),
                'sea_services' => EmployeeSeaService::query()
                    ->where('company_id', $companyId)
                    ->where('vessel_id', $record->id)
                    ->count(),
                'active_crew' => CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->where('vessel_id', $record->id)
                    ->active()
                    ->count(),
            ],
            'can' => VesselPagePermissions::for($user),
            'ranks' => $this->activeRanks(),
            'manning_can' => VesselManningPagePermissions::for($user),
            'manning_health' => (new VesselManningHealthQuery)->forVessel($companyId, (int) $record->id, $user),
            'recent_activity' => RecentActivityQuery::for(
                $user,
                $companyId,
                Vessel::class,
                $record->id,
            ),
            'can_view_audit' => $user?->can('audit.view') ?? false,
            'back_query' => VesselIndexQuery::listBackQueryFromRequest($request),
        ]);
    }

    public function store(StoreVesselRequest $request): JsonResponse|RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->safe()->except(['certificate']);
        $data['company_id'] = $companyId;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['official_no'] = $this->nullableString($data['official_no'] ?? null);
        $data['call_sign'] = $this->nullableString($data['call_sign'] ?? null);
        $data['imo_no'] = $this->nullableString($data['imo_no'] ?? null);

        if ($request->wantsJson()) {
            return $this->createOrReturnExistingQuickCreate(
                $request,
                Vessel::class,
                $data,
                redirect()->route('organization.vessels.index'),
                scopeAttributes: ['company_id' => $companyId],
            );
        }

        $vessel = Vessel::query()->create($data);

        if ($request->hasFile('certificate')) {
            $vessel->update(
                $this->certificateStore->store(
                    $request->file('certificate'),
                    (int) $vessel->id,
                ),
            );
        }

        return redirect()
            ->route('organization.vessels.index')
            ->with('success', 'Vessel created successfully.');
    }

    public function update(UpdateVesselRequest $request, Vessel $vessel): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $vessel->company_id === $companyId, 404);

        $data = $request->safe()->except(['certificate', 'redirect_to']);
        $data['official_no'] = $this->nullableString($data['official_no'] ?? null);
        $data['call_sign'] = $this->nullableString($data['call_sign'] ?? null);
        $data['imo_no'] = $this->nullableString($data['imo_no'] ?? null);

        $vessel->update($data);

        if ($request->hasFile('certificate')) {
            $vessel->update(
                $this->certificateStore->replace(
                    $vessel->fresh() ?? $vessel,
                    $request->file('certificate'),
                ),
            );
        }

        if ($request->input('redirect_to') === 'show') {
            return redirect()
                ->route('organization.vessels.show', $vessel)
                ->with('success', 'Vessel updated successfully.');
        }

        return redirect()
            ->route('organization.vessels.index')
            ->with('success', 'Vessel updated successfully.');
    }

    public function destroy(Request $request, Vessel $vessel): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $vessel->company_id === $companyId, 404);

        if ($blocked = MasterDataUsage::denyDeleteRedirect($vessel, 'organization.vessels.index', $companyId)) {
            return $blocked;
        }

        $vessel->delete();

        return redirect()
            ->route('organization.vessels.index')
            ->with('success', 'Vessel deleted successfully.');
    }

    public function updateManning(UpdateVesselManningRequest $request, Vessel $vessel): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $vessel->company_id === $companyId, 404);

        $company = Company::query()->findOrFail($companyId);

        SyncVesselManning::sync(
            $company,
            $vessel,
            $request->validated('requirements'),
        );

        $backQuery = VesselManningIndexQuery::listBackQueryFromRequest($request);

        if (($request->validated('redirect_to') ?? null) === 'show') {
            return redirect()
                ->route('organization.vessels.show', [
                    'vessel' => $vessel,
                    ...$backQuery,
                ])
                ->with('success', 'Vessel manning updated.');
        }

        return redirect()
            ->route('organization.vessels.index', $request->only(['search', 'vessel_type_id', 'page', 'per_page']))
            ->with('success', 'Vessel manning updated.');
    }

    public function importTemplate(): Response
    {
        $csv = "client,name,vessel_type,grt,bhp,is_active\nADNOC,ADNOC 951,H/LIFT,4500,12000,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vessels-import-template.csv"',
        ]);
    }

    public function import(ImportVesselsRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('organization.vessels.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('organization.vessels.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['name', 'vessel', 'vessel name', 'vessel_name'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['client', 'client_name'], true)) {
                $map['client'] = (int) $index;
            }
            if (in_array($key, ['vessel_type', 'vessel type', 'type'], true)) {
                $map['vessel_type'] = (int) $index;
            }
            if (in_array($key, ['grt', 'gross tonnage', 'gross_tonnage'], true)) {
                $map['grt'] = (int) $index;
            }
            if (in_array($key, ['bhp', 'brake horsepower', 'horsepower'], true)) {
                $map['bhp'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'], $map['vessel_type'], $map['client'])) {
            fclose($handle);

            return redirect()
                ->route('organization.vessels.index')
                ->withErrors(['file' => 'The CSV must include client, name, and vessel_type columns.']);
        }

        $vesselTypes = VesselType::query()->get(['id', 'name']);
        $clientsByName = Client::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->keyBy(fn (Client $client): string => mb_strtolower(trim($client->name)));

        $imported = 0;
        $emptyNames = 0;
        $unknownTypes = 0;
        $unknownClients = 0;
        $unknownClientNames = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($name === '') {
                $emptyNames++;

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

            $typeName = trim((string) ($row[$map['vessel_type']] ?? ''));
            $vesselType = $vesselTypes->first(fn (VesselType $type) => mb_strtolower($type->name) === mb_strtolower($typeName));

            if ($vesselType === null) {
                $unknownTypes++;

                continue;
            }

            $grt = null;
            if (isset($map['grt'])) {
                $grtRaw = trim((string) ($row[$map['grt']] ?? ''));
                if ($grtRaw !== '' && is_numeric($grtRaw)) {
                    $grt = (float) $grtRaw;
                }
            }

            $bhp = null;
            if (isset($map['bhp'])) {
                $bhpRaw = trim((string) ($row[$map['bhp']] ?? ''));
                if ($bhpRaw !== '' && is_numeric($bhpRaw)) {
                    $bhp = (int) $bhpRaw;
                }
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            Vessel::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'name' => $name,
                ],
                [
                    'client_id' => $client->id,
                    'vessel_type_id' => $vesselType->id,
                    'grt' => $grt,
                    'bhp' => $bhp,
                    'is_active' => $active,
                ],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            $unknownList = implode(', ', array_keys($unknownClientNames));

            return redirect()
                ->route('organization.vessels.index')
                ->withErrors([
                    'file' => match (true) {
                        $unknownClients > 0 && $unknownList !== '' => "No rows were imported. Unknown or inactive client(s): {$unknownList}.",
                        $unknownClients > 0 => 'No rows were imported. One or more rows had a missing or unknown client.',
                        $emptyNames > 0 => "No rows were imported. {$emptyNames} row(s) had an empty name.",
                        $unknownTypes > 0 => "No rows were imported. {$unknownTypes} row(s) had an unknown vessel type.",
                        default => 'No rows were imported. Ensure each row has a client, name, and vessel type.',
                    },
                ]);
        }

        $message = "Imported {$imported} vessel row(s).";
        if ($unknownClients > 0) {
            $unknownList = implode(', ', array_keys($unknownClientNames));
            $message .= $unknownList !== ''
                ? " Skipped {$unknownClients} row(s) with unknown/inactive client(s): {$unknownList}."
                : " Skipped {$unknownClients} row(s) with missing or unknown clients.";
        }
        if ($unknownTypes > 0) {
            $message .= " Skipped {$unknownTypes} row(s) with unknown vessel types.";
        }

        return redirect()
            ->route('organization.vessels.index')
            ->with('success', $message);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function vesselTypes(): array
    {
        return VesselType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    private function clients(): array
    {
        return Client::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'is_active' => (bool) $client->is_active,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeRanks(): array
    {
        return Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
