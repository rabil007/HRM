<?php

namespace App\Http\Controllers\Organization;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\TransferEmployeeVisaCompanyContract;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use App\Support\Contracts\SalaryRevisionEffectiveMonth;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Payroll\PayrollRecordLinkage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeContractController extends Controller
{
    public function __construct(
        private readonly UpsertEmployeeContract $upsertEmployeeContract,
        private readonly TransferEmployeeVisaCompanyContract $transferVisaCompany,
    ) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $this->validateContract($request, $employee);

        $attributes = $this->contractAttributes($validated, null, $employee);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['start_date', 'end_date', 'labor_contract_id', 'status', 'payroll_category', 'salary_structure', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'supplementary_allowance', 'site_allowance', 'note'],
            'Enter at least one contract field before saving.',
        );

        $this->upsertEmployeeContract->handle(
            $companyId,
            $employee,
            $attributes,
            createdBy: $request->user()?->id,
        );

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

        $attributes = $this->contractAttributes($validated, $employeeContract, $employee);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['start_date', 'end_date', 'labor_contract_id', 'status', 'payroll_category', 'salary_structure', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'supplementary_allowance', 'site_allowance', 'note'],
            'Enter at least one contract field before saving.',
        );

        $this->upsertEmployeeContract->handle(
            $companyId,
            $employee,
            $attributes,
            $employeeContract,
            createdBy: $request->user()?->id,
            revisionEffectiveFrom: $validated['revision_effective_from'] ?? null,
            revisionReason: $validated['revision_reason'] ?? null,
        );

        return back()->with('success', 'Contract updated.');
    }

    public function transferVisaCompany(Request $request, Employee $employee, EmployeeContract $employeeContract): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $employeeContract->employee_id === $employee->id
            && $employeeContract->company_id === $companyId,
            403,
        );

        $validated = $request->validate([
            'company_visa_type_id' => ['required', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'transfer_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:transfer_date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'payroll_category' => ['nullable', 'in:'.implode(',', PayrollCategory::values())],
            'salary_structure' => ['nullable', 'in:'.implode(',', ContractSalaryStructure::values())],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->transferVisaCompany->handle(
            companyId: $companyId,
            employee: $employee,
            oldContract: $employeeContract,
            newCompanyVisaTypeId: (int) $validated['company_visa_type_id'],
            transferDate: $validated['transfer_date'],
            newContractAttributes: [
                'end_date' => $validated['end_date'] ?? null,
                'labor_contract_id' => $validated['labor_contract_id'] ?? null,
                'payroll_category' => $validated['payroll_category'] ?? null,
                'salary_structure' => $validated['salary_structure'] ?? null,
                'basic_salary' => $validated['basic_salary'] ?? null,
                'housing_allowance' => $validated['housing_allowance'] ?? null,
                'transport_allowance' => $validated['transport_allowance'] ?? null,
                'other_allowances' => $validated['other_allowances'] ?? null,
                'supplementary_allowance' => $validated['supplementary_allowance'] ?? null,
                'site_allowance' => $validated['site_allowance'] ?? null,
                'note' => $validated['note'] ?? null,
            ],
            actorId: $request->user()?->id,
            reason: $validated['reason'] ?? null,
        );

        return back()->with('success', 'Sponsor transferred. A new contract has been created.');
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
        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_contracts', [
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,ended'],
            'payroll_category' => ['nullable', 'in:'.implode(',', PayrollCategory::values())],
            'salary_structure' => ['nullable', 'in:'.implode(',', ContractSalaryStructure::values())],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'revision_effective_from' => ['nullable', 'date'],
            'revision_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $revisionEffectiveFrom = SalaryRevisionEffectiveMonth::tryNormalize(
            $validated['revision_effective_from'] ?? null,
        );

        if ($revisionEffectiveFrom !== null) {
            $validated['revision_effective_from'] = $revisionEffectiveFrom;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function contractAttributes(array $validated, ?EmployeeContract $existing, Employee $employee): array
    {
        return [
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
            'salary_structure' => $this->resolvedSalaryStructureValue($validated, $existing),
            // A new contract with no explicit selection defaults to the employee's
            // current Company Visa Type. Editing an existing contract without
            // resubmitting this field preserves that contract's own historical value.
            'company_visa_type_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'company_visa_type_id',
                $existing !== null ? $existing->company_visa_type_id : $employee->company_visa_type_id,
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolvedSalaryStructureValue(array $validated, ?EmployeeContract $existing): string
    {
        $payrollCategory = EmployeeProfileTemplateRequestRules::hasValidated($validated, 'payroll_category')
            ? ($validated['payroll_category'] ?? PayrollCategory::Office->value)
            : ($existing?->payroll_category?->value ?? PayrollCategory::Office->value);

        if ($payrollCategory === PayrollCategory::Office->value) {
            return ContractSalaryStructure::Monthly->value;
        }

        if (EmployeeProfileTemplateRequestRules::hasValidated($validated, 'salary_structure')) {
            return $validated['salary_structure'] ?? ContractSalaryStructure::Daily->value;
        }

        return $existing?->salary_structure?->value
            ?? $existing?->resolvedSalaryStructure()->value
            ?? ContractSalaryStructure::Daily->value;
    }
}
