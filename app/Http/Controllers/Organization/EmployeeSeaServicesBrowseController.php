<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\SeaServices\SeaServiceAccess;
use App\Support\SeaServices\SeaServiceEmployeeBrowseQuery;
use App\Support\SeaServices\SeaServicePagePermissions;
use App\Support\SeaServices\SeaServiceShowBackNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeSeaServicesBrowseController extends Controller
{
    public function __invoke(Request $request, Employee $employee, SeaServiceEmployeeBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        SeaServiceAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $employee->load('employeeProfileTemplate:id,name,configuration_json');

        $result = $browse->forEmployee($companyId, $employee);
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        return Inertia::render('organization/sea-services/employee', [
            'employee' => $result['employee'],
            'sea_services' => $result['sea_services'],
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
            'back' => SeaServiceShowBackNavigation::resolveIndex($request),
            'can' => SeaServicePagePermissions::for($request->user()),
        ]);
    }
}
