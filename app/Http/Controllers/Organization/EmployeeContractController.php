<?php

namespace App\Http\Controllers\Organization;

use App\Enums\PayrollCategory;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\PayrollRecordLinkage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeContractController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $this->validateContract($request, $employee);

        $attributes = $this->contractAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['contract_type', 'start_date', 'end_date', 'labor_contract_id', 'status', 'payroll_category', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'supplementary_allowance', 'site_allowance', 'note'],
            'Enter at least one contract field before saving.',
        );

        if (($attributes['status'] ?? 'active') === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id);
        }

        $contract = EmployeeContract::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            ...$attributes,
        ]);

        (new SyncContractSalaryComponentsFromContract)->handle($contract);

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

        $attributes = $this->contractAttributes($validated, $employeeContract);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['contract_type', 'start_date', 'end_date', 'labor_contract_id', 'status', 'payroll_category', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'supplementary_allowance', 'site_allowance', 'note'],
            'Enter at least one contract field before saving.',
        );

        if (($attributes['status'] ?? $employeeContract->status) === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id, $employeeContract->id);
        }

        $employeeContract->update($attributes);

        (new SyncContractSalaryComponentsFromContract)->handle($employeeContract->fresh());

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

        if (PayrollRecordLinkage::contractHasRecords((int) $employeeContract->id)) {
            return back()->withErrors([
                'employee_contract' => 'This contract cannot be deleted because it is linked to pay run records.',
            ]);
        }

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
            'payroll_category' => ['nullable', 'in:'.implode(',', PayrollCategory::values())],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function contractAttributes(array $validated, ?EmployeeContract $existing): array
    {
        return [
            'contract_type' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'contract_type',
                $existing?->contract_type ?? 'unlimited',
            ),
            'start_date' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'start_date',
                $existing?->start_date,
            ),
            'payroll_category' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'payroll_category',
                $existing?->payroll_category?->value ?? PayrollCategory::Office->value,
            ),
            'end_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'end_date')
                ? ($validated['end_date'] ?? null)
                : $existing?->end_date,
            'labor_contract_id' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'labor_contract_id')
                ? (isset($validated['labor_contract_id']) && $validated['labor_contract_id'] !== ''
                    ? $validated['labor_contract_id']
                    : null)
                : $existing?->labor_contract_id,
            'status' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
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
            'supplementary_allowance' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'supplementary_allowance')
                ? ($validated['supplementary_allowance'] ?? null)
                : $existing?->supplementary_allowance,
            'site_allowance' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'site_allowance')
                ? ($validated['site_allowance'] ?? null)
                : $existing?->site_allowance,
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
