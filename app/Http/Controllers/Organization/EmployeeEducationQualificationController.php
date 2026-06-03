<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeEducationQualification;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeEducationQualificationController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_education_qualifications',
            $this->educationRules(),
        );

        $attributes = $this->educationAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['certificate', 'issue_date', 'university', 'country_id'],
            'Enter at least one education field before saving.',
        );

        EmployeeEducationQualification::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            ...$attributes,
        ]);

        return back()->with('success', 'Education qualification added.');
    }

    public function update(Request $request, Employee $employee, EmployeeEducationQualification $qualification): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $qualification->employee_id === $employee->id
            && $qualification->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_education_qualifications',
            $this->educationRules(),
        );

        $attributes = $this->educationAttributes($validated, $qualification);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['certificate', 'issue_date', 'university', 'country_id'],
            'Enter at least one education field before saving.',
        );

        $qualification->update($attributes);

        return back()->with('success', 'Education qualification updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeEducationQualification $qualification): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $qualification->employee_id === $employee->id
            && $qualification->company_id === $companyId,
            403,
        );

        $qualification->delete();

        return back()->with('success', 'Education qualification removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function educationRules(): array
    {
        return [
            'certificate' => ['required', 'string', 'max:200'],
            'issue_date' => ['nullable', 'date'],
            'university' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function educationAttributes(array $validated, ?EmployeeEducationQualification $existing = null): array
    {
        return [
            'certificate' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'certificate',
                $existing?->certificate,
            ),
            'issue_date' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'issue_date',
                $existing?->issue_date,
            ),
            'university' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'university',
                $existing?->university,
            ),
            'country_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'country_id',
                $existing?->country_id,
                asInteger: true,
            ),
        ];
    }
}
