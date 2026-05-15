<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeEducationQualification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeEducationQualificationController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'certificate' => ['required', 'string', 'max:200'],
            'issue_date' => ['nullable', 'date'],
            'university' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
        ]);

        EmployeeEducationQualification::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'certificate' => $validated['certificate'],
            'issue_date' => $validated['issue_date'] ?? null,
            'university' => $validated['university'] ?? null,
            'country_id' => $validated['country_id'] ?? null,
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

        $validated = $request->validate([
            'certificate' => ['required', 'string', 'max:200'],
            'issue_date' => ['nullable', 'date'],
            'university' => ['nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
        ]);

        $qualification->update([
            'certificate' => $validated['certificate'],
            'issue_date' => $validated['issue_date'] ?? null,
            'university' => $validated['university'] ?? null,
            'country_id' => $validated['country_id'] ?? null,
        ]);

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
}
