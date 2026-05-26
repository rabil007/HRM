<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeContractController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $this->validateContract($request, $employee);

        if (($validated['status'] ?? 'active') === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id);
        }

        EmployeeContract::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            ...$this->contractAttributes($validated, null),
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

        $validated = $this->validateContract($request, $employee);

        if (($validated['status'] ?? $employeeContract->status) === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id, $employeeContract->id);
        }

        $employeeContract->update($this->contractAttributes($validated, $employeeContract));

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
    private function validateContract(Request $request, Employee $employee): array
    {
        return EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_contracts', [
            'contract_type' => ['required', 'in:limited,unlimited,part_time,contract'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,ended,draft'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function contractAttributes(array $validated, ?EmployeeContract $existing): array
    {
        return [
            'contract_type' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'contract_type',
                $existing?->contract_type ?? 'unlimited',
            ),
            'start_date' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'start_date',
                $existing?->start_date,
            ),
            'end_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'end_date')
                ? ($validated['end_date'] ?? null)
                : $existing?->end_date,
            'labor_contract_id' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'labor_contract_id')
                ? (isset($validated['labor_contract_id']) && $validated['labor_contract_id'] !== ''
                    ? $validated['labor_contract_id']
                    : null)
                : $existing?->labor_contract_id,
            'status' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'status',
                $existing?->status ?? 'active',
            ),
            'basic_salary' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'basic_salary')
                ? ($validated['basic_salary'] ?? null)
                : $existing?->basic_salary,
            'housing_allowance' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'housing_allowance')
                ? ($validated['housing_allowance'] ?? null)
                : $existing?->housing_allowance,
            'transport_allowance' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'transport_allowance')
                ? ($validated['transport_allowance'] ?? null)
                : $existing?->transport_allowance,
            'other_allowances' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'other_allowances')
                ? ($validated['other_allowances'] ?? null)
                : $existing?->other_allowances,
            'note' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'note')
                ? (isset($validated['note']) && trim((string) $validated['note']) !== ''
                    ? trim((string) $validated['note'])
                    : null)
                : $existing?->note,
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
