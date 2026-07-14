<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Contracts\StoreContractSalaryRevisionRequest;
use App\Models\ContractSalaryRevision;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\DeleteContractSalaryRevision;
use App\Support\Contracts\Actions\UpdateContractSalaryRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContractSalaryRevisionController extends Controller
{
    public function __construct(
        private readonly ApplyContractSalaryRevision $applySalaryRevision,
        private readonly UpdateContractSalaryRevision $updateSalaryRevision,
        private readonly DeleteContractSalaryRevision $deleteSalaryRevision,
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
            $this->amountsFromValidated($validated),
            $validated['effective_from'],
            $validated['reason'] ?? null,
            $request->user()?->id,
        );

        return back()->with('success', 'Salary revision applied.');
    }

    public function update(
        StoreContractSalaryRevisionRequest $request,
        Employee $employee,
        EmployeeContract $employeeContract,
        ContractSalaryRevision $salaryRevision,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $employeeContract->employee_id === $employee->id
            && $employeeContract->company_id === $companyId
            && $salaryRevision->contract_id === $employeeContract->id
            && $salaryRevision->company_id === $companyId,
            403,
        );

        $validated = $request->validated();

        $this->updateSalaryRevision->handle(
            $employeeContract,
            $salaryRevision,
            $this->amountsFromValidated($validated),
            $validated['effective_from'],
            $validated['reason'] ?? null,
        );

        return back()->with('success', 'Salary revision updated.');
    }

    public function destroy(
        Request $request,
        Employee $employee,
        EmployeeContract $employeeContract,
        ContractSalaryRevision $salaryRevision,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $employeeContract->employee_id === $employee->id
            && $employeeContract->company_id === $companyId
            && $salaryRevision->contract_id === $employeeContract->id
            && $salaryRevision->company_id === $companyId,
            403,
        );

        $this->deleteSalaryRevision->handle($employeeContract, $salaryRevision);

        return back()->with('success', 'Salary revision deleted.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, float|int|string|null>
     */
    private function amountsFromValidated(array $validated): array
    {
        return [
            'basic_salary' => $validated['basic_salary'] ?? null,
            'housing_allowance' => $validated['housing_allowance'] ?? null,
            'transport_allowance' => $validated['transport_allowance'] ?? null,
            'other_allowances' => $validated['other_allowances'] ?? null,
            'supplementary_allowance' => $validated['supplementary_allowance'] ?? null,
            'site_allowance' => $validated['site_allowance'] ?? null,
        ];
    }
}
