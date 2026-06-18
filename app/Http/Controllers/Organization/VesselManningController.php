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

        $ranks = Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $vesselTypes = VesselType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('organization/vessel-manning/index', [
            'vessels' => $vessels->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'vessel_type_id' => $vesselTypeId,
            ],
            'ranks' => $ranks,
            'vessel_types' => $vesselTypes,
            'can' => [
                'manage' => $request->user()?->can('crew_operations.vessel_manning.manage') ?? false,
            ],
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

        return redirect()
            ->route('organization.vessel-manning.index', $request->only(['search', 'vessel_type_id', 'page', 'per_page']))
            ->with('success', 'Vessel manning updated.');
    }
}
