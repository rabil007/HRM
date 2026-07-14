<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeContract;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Contracts\ContractListResource;
use App\Support\Contracts\ContractPagePermissions;
use App\Support\Contracts\ContractShowBackNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractShowController extends Controller
{
    public function __invoke(Request $request, EmployeeContract $employeeContract): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employeeContract->company_id === $companyId, 404);

        $employeeContract->load([
            'employee.department:id,name',
            'employee.position:id,title',
            'employee.employeeProfileTemplate:id,name',
            'salaryRevisions' => fn ($query) => $query
                ->with('lines')
                ->orderByDesc('version'),
        ]);

        $employeeContract->setAttribute(
            'total_contracts',
            EmployeeContract::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeContract->employee_id)
                ->count(),
        );

        return Inertia::render('organization/contracts/show', [
            'contract' => ContractListResource::toArray($employeeContract),
            'can' => ContractPagePermissions::for($request->user()),
            'back' => ContractShowBackNavigation::resolve($request),
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                EmployeeContract::class,
                $employeeContract->id,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }
}
