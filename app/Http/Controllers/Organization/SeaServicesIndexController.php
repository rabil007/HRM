<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\SeaServices\SeaServiceDepartmentTree;
use App\Support\SeaServices\SeaServiceDirectoryFilters;
use App\Support\SeaServices\SeaServiceDirectoryQuery;
use App\Support\SeaServices\SeaServicePagePermissions;
use App\Support\SeaServices\SeaServiceSummaryQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SeaServicesIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, SeaServiceSummaryQuery $summaryQuery)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = SeaServiceDirectoryFilters::fromRequest($request);
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = (new SeaServiceDirectoryQuery($companyId, $filters))->paginate($perPage);

        return Inertia::render('organization/sea-services/index', [
            'summary' => $summaryQuery->forCompany($companyId, $filters),
            'search' => $filters->search,
            'vessel_id' => $filters->vesselId,
            'vessel_type_id' => $filters->vesselTypeId,
            'rank_id' => $filters->rankId,
            'client_id' => $filters->clientId,
            'offshore' => $filters->offshore,
            'active' => $filters->active,
            'start_date' => $filters->startDate,
            'end_date' => $filters->endDate,
            'branch_id' => $filters->branchId,
            'department_id' => $filters->departmentId,
            'department_tree' => SeaServiceDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $filters->departmentId),
            ),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'sea_services' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'vessel_types' => VesselType::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (VesselType $row) => ['id' => $row->id, 'name' => $row->name])
                ->values()
                ->all(),
            'vessels' => Vessel::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'vessel_type_id'])
                ->map(fn (Vessel $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'vessel_type_id' => $row->vessel_type_id,
                ])
                ->values()
                ->all(),
            'ranks' => Rank::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Rank $row) => ['id' => $row->id, 'name' => $row->name])
                ->values()
                ->all(),
            'clients' => Client::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $row) => ['id' => $row->id, 'name' => $row->name])
                ->values()
                ->all(),
            'can' => SeaServicePagePermissions::for($request->user()),
        ]);
    }
}
