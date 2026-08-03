<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\BulkDestroyEmployeeSeaServicesRequest;
use App\Http\Requests\Organization\Employee\ImportEmployeeSeaServiceRequest;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Employees\SeaServiceDuration;
use App\Support\SeaServices\SeaServiceImportOrchestrator;
use App\Support\SeaServices\SeaServiceImportTemplateExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeSeaServiceController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_sea_services',
            $this->seaServiceRules(),
        );

        $attributes = $this->seaServiceAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['vessel_type_id', 'vessel_id', 'rank_id', 'start_date', 'end_date', 'client_id'],
            'Enter at least one sea service field before saving.',
        );

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeSeaService::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$attributes,
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

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_sea_services',
            $this->seaServiceRules(),
        );

        $attributes = $this->seaServiceAttributes($validated, $seaService);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['vessel_type_id', 'vessel_id', 'rank_id', 'start_date', 'end_date', 'client_id'],
            'Enter at least one sea service field before saving.',
        );

        $seaService->update($attributes);

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

    public function bulkDestroy(
        BulkDestroyEmployeeSeaServicesRequest $request,
        Employee $employee,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $deleted = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->whereIn('id', $request->validated('sea_service_ids'))
            ->delete();

        if ($deleted === 0) {
            return back()->with('error', 'No sea service records could be deleted.');
        }

        $label = $deleted === 1 ? '1 sea service record' : "{$deleted} sea service records";

        return back()->with('success', "Deleted {$label}.");
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

        DB::transaction(function () use ($validated, $companyId, $employee): void {
            foreach ($validated['order'] as $idx => $id) {
                EmployeeSeaService::query()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employee->id)
                    ->whereKey($id)
                    ->update(['sort_order' => $idx]);
            }
        });

        return back()->with('success', 'Sea service order saved.');
    }

    public function importTemplate(
        Request $request,
        Employee $employee,
        SeaServiceImportTemplateExporter $exporter,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless((int) $employee->company_id === $companyId, 404);

        try {
            $result = $exporter->exportForEmployee($companyId, $employee);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importPreview(
        ImportEmployeeSeaServiceRequest $request,
        Employee $employee,
        SeaServiceImportOrchestrator $orchestrator,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless((int) $employee->company_id === $companyId, 404);

        try {
            $result = $orchestrator->preview(
                $companyId,
                $request->file('file'),
                $employee,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }

    public function import(
        ImportEmployeeSeaServiceRequest $request,
        Employee $employee,
        SeaServiceImportOrchestrator $orchestrator,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless((int) $employee->company_id === $companyId, 404);

        try {
            $result = $orchestrator->execute(
                $companyId,
                $request->file('file'),
                $employee,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            "Imported {$result['imported']} sea service row(s). Skipped {$result['skipped']} row(s).",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seaServiceRules(): array
    {
        return [
            'vessel_type_id' => ['required', Rule::exists('vessel_types', 'id')->where('is_active', true)],
            'vessel_id' => ['required', Rule::exists('vessels', 'id')->where('is_active', true)],
            'rank_id' => ['required', Rule::exists('ranks', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function seaServiceAttributes(array $validated, ?EmployeeSeaService $existing = null): array
    {
        $startDate = EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            'start_date',
            $existing?->start_date,
        );
        $endDate = EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            'end_date',
            $existing?->end_date,
        );

        if ($startDate !== null && $endDate !== null) {
            $duration = SeaServiceDuration::fromDates(
                (string) $startDate,
                (string) $endDate,
            );
        } else {
            $duration = [
                'months' => (int) ($existing?->total_months ?? 0),
                'days' => (int) ($existing?->total_days ?? 0),
            ];
        }

        $vesselId = EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            'vessel_id',
            $existing?->vessel_id,
            asInteger: true,
        );

        $vesselTypeId = EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            'vessel_type_id',
            $existing?->vessel_type_id,
            asInteger: true,
        );

        if ($vesselId !== null) {
            $vessel = Vessel::query()->find($vesselId);
            if ($vessel !== null) {
                $vesselTypeId = $vessel->vessel_type_id;
            }
        }

        return [
            'vessel_type_id' => $vesselTypeId,
            'vessel_id' => $vesselId,
            'rank_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'rank_id',
                $existing?->rank_id,
                asInteger: true,
            ),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_months' => $duration['months'],
            'total_days' => $duration['days'],
            'client_id' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'client_id')
                ? (isset($validated['client_id']) ? (int) $validated['client_id'] : null)
                : $existing?->client_id,
        ];
    }
}
