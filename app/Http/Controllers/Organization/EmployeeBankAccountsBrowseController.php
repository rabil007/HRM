<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Employee;
use App\Support\BankAccounts\BankAccountAccess;
use App\Support\BankAccounts\BankAccountEmployeeBrowseQuery;
use App\Support\BankAccounts\BankAccountPagePermissions;
use App\Support\BankAccounts\BankAccountShowBackNavigation;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeBankAccountsBrowseController extends Controller
{
    public function __invoke(Request $request, Employee $employee, BankAccountEmployeeBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        BankAccountAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $employee->load('employeeProfileTemplate:id,name,configuration_json');

        $result = $browse->forEmployee($companyId, $employee);
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        $banks = Bank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('organization/bank-accounts/employee', [
            'employee' => $result['employee'],
            'bank_accounts' => $result['bank_accounts'],
            'banks' => $banks,
            'template_bank_account_fields' => $resolved['fields']['employee_bank_accounts'] ?? null,
            'back' => BankAccountShowBackNavigation::resolve($request),
            'can' => BankAccountPagePermissions::for($request->user()),
        ]);
    }
}
