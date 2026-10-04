<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\CrewOperations\CrewReadinessFilters;
use App\Support\CrewOperations\CrewReadinessQuery;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Vessels\ResolvesCompanyVessels;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CrewReadinessController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private readonly CrewReadinessQuery $readinessQuery,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        if (! $user->can('crew_operations.planning.view') && ! $user->can('crew_operations.assignments.view')) {
            abort(403);
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $rawFilters = is_array($request->query()) ? $request->query() : [];
        $page = max(1, (int) $request->query('page', 1));

        $data = $this->readinessQuery->page(
            companyId: $companyId,
            filters: $rawFilters,
            user: $user,
            page: $page,
            path: $request->url(),
            queryString: $rawFilters,
        );

        return Inertia::render('organization/crew-readiness/index', [
            'readiness' => [
                'rows' => $data['rows'],
                'pagination' => $this->paginationMeta($data['pagination']),
                'summary' => $data['summary'],
                'filters' => $data['filters'],
                'filter_options' => $data['filter_options'],
                'has_active_query' => CrewReadinessFilters::hasActiveQuery($data['filters']),
            ],
            'vessels' => ResolvesCompanyVessels::activeOptions($companyId, requireAssignedClient: false),
            'positions' => CrewPositionCatalog::crewPositionOptions($companyId),
        ]);
    }
}
