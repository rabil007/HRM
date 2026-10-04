<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\CrewPlanning\CrewReliefDeskFilters;
use App\Support\CrewPlanning\CrewReliefDeskQuery;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Vessels\ResolvesCompanyVessels;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewReliefDeskController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private readonly CrewReliefDeskQuery $reliefDeskQuery,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        $deskFilters = CrewReliefDeskFilters::fromRequest($request);

        $desk = $this->reliefDeskQuery->page(
            $companyId,
            $deskFilters,
            $user,
            max(1, (int) $request->query('page', 1)),
            $request->url(),
            is_array($request->query()) ? $request->query() : [],
        );

        return Inertia::render('organization/crew-relief-desk/index', [
            'relief_desk' => [
                'rows' => $desk['rows'],
                'pagination' => $this->paginationMeta($desk['pagination']),
                'summary' => $desk['summary'],
                'filters' => $desk['filters'],
                'filter_options' => $desk['filter_options'],
                'has_active_query' => CrewReliefDeskFilters::hasActiveQuery($deskFilters),
            ],
            'vessels' => ResolvesCompanyVessels::activeOptions($companyId, requireAssignedClient: true),
            'positions' => CrewPositionCatalog::crewPositionOptions($companyId),
        ]);
    }
}
