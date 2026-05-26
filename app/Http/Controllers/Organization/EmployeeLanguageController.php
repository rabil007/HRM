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

        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_languages', [
            'language_name' => ['required', 'string', 'max:255'],
            'is_spoken' => ['sometimes', 'boolean'],
            'is_written' => ['sometimes', 'boolean'],
            'is_understood' => ['sometimes', 'boolean'],
            'is_mother_tongue' => ['sometimes', 'boolean'],
        ]);

        $maxSort = EmployeeLanguage::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeLanguage::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            'language_name' => $validated['language_name'],
            'is_spoken' => (bool) ($validated['is_spoken'] ?? false),
            'is_written' => (bool) ($validated['is_written'] ?? false),
            'is_understood' => (bool) ($validated['is_understood'] ?? false),
            'is_mother_tongue' => (bool) ($validated['is_mother_tongue'] ?? false),
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

        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_languages', [
            'language_name' => ['required', 'string', 'max:255'],
            'is_spoken' => ['sometimes', 'boolean'],
            'is_written' => ['sometimes', 'boolean'],
            'is_understood' => ['sometimes', 'boolean'],
            'is_mother_tongue' => ['sometimes', 'boolean'],
        ]);

        $language->update([
            'language_name' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'language_name',
                $language->language_name,
            ),
            'is_spoken' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_spoken')
                ? (bool) ($validated['is_spoken'] ?? false)
                : $language->is_spoken,
            'is_written' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_written')
                ? (bool) ($validated['is_written'] ?? false)
                : $language->is_written,
            'is_understood' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_understood')
                ? (bool) ($validated['is_understood'] ?? false)
                : $language->is_understood,
            'is_mother_tongue' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_mother_tongue')
                ? (bool) ($validated['is_mother_tongue'] ?? false)
                : $language->is_mother_tongue,
        ]);

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
}
