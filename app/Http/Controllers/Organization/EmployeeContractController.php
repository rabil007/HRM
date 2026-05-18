<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeContractController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $this->validateContract($request);

        if (($validated['status'] ?? 'active') === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id);
        }

        EmployeeContract::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            ...$this->contractAttributes($validated),
        ]);

        return back()->with('success', 'Contract added.');
    }

    public function update(Request $request, Employee $employee, EmployeeContract $employeeContract): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $employeeContract->employee_id === $employee->id
            && $employeeContract->company_id === $companyId,
            403,
        );

        $validated = $this->validateContract($request);

        if (($validated['status'] ?? $employeeContract->status) === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id, $employeeContract->id);
        }

        $employeeContract->update($this->contractAttributes($validated));

        return back()->with('success', 'Contract updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeContract $employeeContract): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $employeeContract->employee_id === $employee->id
            && $employeeContract->company_id === $companyId,
            403,
        );

        $employeeContract->delete();

        return back()->with('success', 'Contract removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateContract(Request $request): array
    {
        return $request->validate([
            'contract_type' => ['required', 'in:limited,unlimited,part_time,contract'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,ended,draft'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function contractAttributes(array $validated): array
    {
        return [
            'contract_type' => $validated['contract_type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'labor_contract_id' => isset($validated['labor_contract_id']) && $validated['labor_contract_id'] !== ''
                ? $validated['labor_contract_id']
                : null,
            'status' => $validated['status'],
            'basic_salary' => $validated['basic_salary'] ?? null,
            'housing_allowance' => $validated['housing_allowance'] ?? null,
            'transport_allowance' => $validated['transport_allowance'] ?? null,
            'other_allowances' => $validated['other_allowances'] ?? null,
        ];
    }

    private function deactivateOtherContracts(int $companyId, int $employeeId, ?int $exceptId = null): void
    {
        EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['status' => 'ended']);
    }
}
