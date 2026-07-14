<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Contracts\StoreContractSalaryRevisionRequest;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use Illuminate\Http\RedirectResponse;

class ContractSalaryRevisionController extends Controller
{
    public function __construct(
        private readonly ApplyContractSalaryRevision $applySalaryRevision,
    ) {}

    public function store(
        StoreContractSalaryRevisionRequest $request,
        Employee $employee,
        EmployeeContract $employeeContract,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $employeeContract->employee_id === $employee->id
            && $employeeContract->company_id === $companyId,
            403,
        );

        $validated = $request->validated();

        $this->applySalaryRevision->handle(
            $employeeContract,
            [
                'basic_salary' => $validated['basic_salary'] ?? null,
                'housing_allowance' => $validated['housing_allowance'] ?? null,
                'transport_allowance' => $validated['transport_allowance'] ?? null,
                'other_allowances' => $validated['other_allowances'] ?? null,
                'supplementary_allowance' => $validated['supplementary_allowance'] ?? null,
                'site_allowance' => $validated['site_allowance'] ?? null,
            ],
            $validated['effective_from'],
            $validated['reason'] ?? null,
            $request->user()?->id,
        );

        return back()->with('success', 'Salary revision applied.');
    }
}
