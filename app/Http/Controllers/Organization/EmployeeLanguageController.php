<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLanguage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeLanguageController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
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

        $validated = $request->validate([
            'language_name' => ['required', 'string', 'max:255'],
            'is_spoken' => ['sometimes', 'boolean'],
            'is_written' => ['sometimes', 'boolean'],
            'is_understood' => ['sometimes', 'boolean'],
            'is_mother_tongue' => ['sometimes', 'boolean'],
        ]);

        $language->update([
            'language_name' => $validated['language_name'],
            'is_spoken' => (bool) ($validated['is_spoken'] ?? false),
            'is_written' => (bool) ($validated['is_written'] ?? false),
            'is_understood' => (bool) ($validated['is_understood'] ?? false),
            'is_mother_tongue' => (bool) ($validated['is_mother_tongue'] ?? false),
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
