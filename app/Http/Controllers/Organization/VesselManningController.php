<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\VesselManning\UpdateVesselManningRequest;
use App\Models\Company;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\VesselManning\SyncVesselManning;
use App\Support\VesselManning\VesselManningIndexQuery;
use App\Support\VesselManning\VesselManningPagePermissions;
use App\Support\VesselManning\VesselManningRecentActivityQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VesselManningController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $vesselTypeId = $request->query('vessel_type_id');
        $vesselTypeId = $vesselTypeId !== null && $vesselTypeId !== '' ? (int) $vesselTypeId : null;

        $paginator = VesselManningIndexQuery::paginate($companyId, $search, $vesselTypeId, $perPage);

        $vessels = $paginator->through(fn (Vessel $vessel) => VesselManningIndexQuery::toArray($vessel));

        return Inertia::render('organization/vessel-manning/index', [
            'vessels' => $vessels->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'vessel_type_id' => $vesselTypeId,
            ],
            'ranks' => $this->activeRanks(),
            'vessel_types' => $this->activeVesselTypes(),
            'can' => VesselManningPagePermissions::for($request->user()),
        ]);
    }

    public function show(Request $request, Vessel $vessel): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $record = VesselManningIndexQuery::findForCompany($companyId, $vessel);

        abort_unless($record instanceof Vessel, 404);

        return Inertia::render('organization/vessel-manning/show', [
            'vessel' => VesselManningIndexQuery::toArray($record, includeDetails: true),
            'recent_activity' => VesselManningRecentActivityQuery::forVessel(
                $request->user(),
                $companyId,
                $record->id,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
            'can' => VesselManningPagePermissions::for($request->user()),
            'ranks' => $this->activeRanks(),
            'back_query' => VesselManningIndexQuery::listBackQueryFromRequest($request),
        ]);
    }

    public function update(UpdateVesselManningRequest $request, Vessel $vessel): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        SyncVesselManning::sync(
            $company,
            $vessel,
            $request->validated('requirements'),
        );

        if (($request->validated('redirect_to') ?? null) === 'show') {
            return redirect()
                ->route('organization.vessel-manning.show', [
                    'vessel' => $vessel,
                    ...VesselManningIndexQuery::listBackQueryFromRequest($request),
                ])
                ->with('success', 'Vessel manning updated.');
        }

        return redirect()
            ->route('organization.vessel-manning.index', $request->only(['search', 'vessel_type_id', 'page', 'per_page']))
            ->with('success', 'Vessel manning updated.');
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

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeVesselTypes(): array
    {
        return VesselType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
