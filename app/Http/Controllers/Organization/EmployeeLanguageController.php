<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLanguage;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeLanguageController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_languages',
            $this->languageRules(),
        );

        $attributes = $this->languageAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['language_name'],
            'Enter at least one language field before saving.',
        );

        $maxSort = EmployeeLanguage::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeLanguage::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$attributes,
        ]);

        return back()->with('success', 'Language added.');
    }

    public function update(Request $request, Employee $employee, EmployeeLanguage $language): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $language->employee_id === $employee->id
            && $language->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_languages',
            $this->languageRules(),
        );

        $attributes = $this->languageAttributes($validated, $language);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['language_name'],
            'Enter at least one language field before saving.',
        );

        $language->update($attributes);

        return back()->with('success', 'Language updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeLanguage $language): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $language->employee_id === $employee->id
            && $language->company_id === $companyId,
            403,
        );

        $language->delete();

        return back()->with('success', 'Language removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function languageRules(): array
    {
        return [
            'language_name' => ['required', 'string', 'max:255'],
            'is_spoken' => ['sometimes', 'boolean'],
            'is_written' => ['sometimes', 'boolean'],
            'is_understood' => ['sometimes', 'boolean'],
            'is_mother_tongue' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function languageAttributes(array $validated, ?EmployeeLanguage $existing = null): array
    {
        return [
            'language_name' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'language_name',
                $existing?->language_name,
            ),
            'is_spoken' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_spoken')
                ? (bool) ($validated['is_spoken'] ?? false)
                : (bool) ($existing?->is_spoken ?? false),
            'is_written' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_written')
                ? (bool) ($validated['is_written'] ?? false)
                : (bool) ($existing?->is_written ?? false),
            'is_understood' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_understood')
                ? (bool) ($validated['is_understood'] ?? false)
                : (bool) ($existing?->is_understood ?? false),
            'is_mother_tongue' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_mother_tongue')
                ? (bool) ($validated['is_mother_tongue'] ?? false)
                : (bool) ($existing?->is_mother_tongue ?? false),
        ];
    }
}
