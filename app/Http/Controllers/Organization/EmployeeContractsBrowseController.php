<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Contracts\ContractAccess;
use App\Support\Contracts\ContractEmployeeBrowseQuery;
use App\Support\Contracts\ContractPagePermissions;
use App\Support\Contracts\ContractShowBackNavigation;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeContractsBrowseController extends Controller
{
    public function __invoke(Request $request, Employee $employee, ContractEmployeeBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        ContractAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $employee->load('employeeProfileTemplate:id,name,configuration_json');

        $result = $browse->forEmployee($companyId, $employee);
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        return Inertia::render('organization/contracts/employee', [
            'employee' => $result['employee'],
            'contracts' => $result['contracts'],
            'template_contract_fields' => $resolved['fields']['employee_contracts'] ?? null,
            'back' => ContractShowBackNavigation::resolve($request),
            'can' => ContractPagePermissions::for($request->user()),
        ]);
    }
}
