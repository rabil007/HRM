<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\VesselManning\UpdateVesselManningRequest;
use App\Models\Company;
use App\Models\Vessel;
use App\Support\VesselManning\SyncVesselManning;
use App\Support\VesselManning\VesselManningIndexQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VesselManningController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('organization.vessels.index', $request->query());
    }

    public function show(Request $request, Vessel $vessel): RedirectResponse
    {
        return redirect()->route('organization.vessels.show', [
            'vessel' => $vessel,
            ...$request->query(),
        ]);
    }

    public function update(UpdateVesselManningRequest $request, Vessel $vessel): RedirectResponse
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
}
