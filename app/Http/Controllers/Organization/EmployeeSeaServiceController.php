<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeSeaServiceController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'vessel_id' => ['required', 'exists:vessels,id'],
            'rank_id' => ['required', 'exists:ranks,id'],
            'total_months' => ['required', 'integer', 'min:0', 'max:1200'],
            'total_days' => ['required', 'integer', 'min:0', 'max:366'],
            'grt' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'bhp' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'is_offshore' => ['sometimes', 'boolean'],
        ]);

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeSeaService::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            'vessel_id' => (int) $validated['vessel_id'],
            'rank_id' => (int) $validated['rank_id'],
            'total_months' => $validated['total_months'],
            'total_days' => $validated['total_days'],
            'grt' => $validated['grt'] ?? null,
            'bhp' => isset($validated['bhp']) ? (int) $validated['bhp'] : null,
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'is_offshore' => (bool) ($validated['is_offshore'] ?? false),
        ]);

        return back()->with('success', 'Sea service record added.');
    }

    public function update(Request $request, Employee $employee, EmployeeSeaService $seaService): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $seaService->employee_id === $employee->id
            && $seaService->company_id === $companyId,
            403,
        );

        $validated = $request->validate([
            'vessel_id' => ['required', 'exists:vessels,id'],
            'rank_id' => ['required', 'exists:ranks,id'],
            'total_months' => ['required', 'integer', 'min:0', 'max:1200'],
            'total_days' => ['required', 'integer', 'min:0', 'max:366'],
            'grt' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'bhp' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'is_offshore' => ['sometimes', 'boolean'],
        ]);

        $seaService->update([
            'vessel_id' => (int) $validated['vessel_id'],
            'rank_id' => (int) $validated['rank_id'],
            'total_months' => $validated['total_months'],
            'total_days' => $validated['total_days'],
            'grt' => $validated['grt'] ?? null,
            'bhp' => isset($validated['bhp']) ? (int) $validated['bhp'] : null,
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'is_offshore' => (bool) ($validated['is_offshore'] ?? false),
        ]);

        return back()->with('success', 'Sea service record updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeSeaService $seaService): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $seaService->employee_id === $employee->id
            && $seaService->company_id === $companyId,
            403,
        );

        $seaService->delete();

        return back()->with('success', 'Sea service record removed.');
    }

    public function reorder(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:employee_sea_services,id'],
        ]);

        $ownedIds = EmployeeSeaService::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderBy('id')
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $sentIds = collect($validated['order'])->sort()->values()->all();

        abort_if($ownedIds !== $sentIds, 422);

        foreach ($validated['order'] as $idx => $id) {
            EmployeeSeaService::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->whereKey($id)
                ->update(['sort_order' => $idx]);
        }

        return back()->with('success', 'Sea service order saved.');
    }
}
