<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Activity\RecentActivityQuery;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\SeaServices\SeaServiceAccess;
use App\Support\SeaServices\SeaServiceListResource;
use App\Support\SeaServices\SeaServicePagePermissions;
use App\Support\SeaServices\SeaServiceShowBackNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SeaServiceShowController extends Controller
{
    public function __invoke(Request $request, EmployeeSeaService $seaService)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        SeaServiceAccess::assertSeaServiceInCompany($seaService, $companyId, 404);

        $seaService->load([
            'employee:id,name,employee_no,image,company_id,department_id,position_id,employee_profile_template_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'employee.employeeProfileTemplate:id,name,configuration_json',
            'vesselType:id,name',
            'vessel:id,name',
            'rank:id,name',
            'client:id,name',
        ]);

        $employee = $seaService->employee;
        $resolved = EmployeeProfileTemplateResolver::resolve($employee?->employeeProfileTemplate);

        return Inertia::render('organization/sea-services/show', [
            'sea_service' => SeaServiceListResource::toArray($seaService),
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
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
            'template_fields' => $resolved['fields']['employee_sea_services'] ?? null,
            'can' => SeaServicePagePermissions::for($request->user()),
            'back' => SeaServiceShowBackNavigation::resolve($request, $employee),
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                EmployeeSeaService::class,
                $seaService->id,
                limit: 20,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }
}
