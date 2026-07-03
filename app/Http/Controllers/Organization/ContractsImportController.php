<?php

namespace App\Http\Controllers\Organization;

use App\Enums\PayrollCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Contracts\ImportContractsRequest;
use App\Support\Contracts\ContractImportOrchestrator;
use App\Support\Contracts\ContractImportTemplateExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContractsImportController extends Controller
{
    public function importTemplate(Request $request, ContractImportTemplateExporter $exporter)
    {
        $validated = $request->validate([
            'payroll_category' => ['required', Rule::in(PayrollCategory::values())],
        ]);

        $companyId = (int) $request->attributes->get('current_company_id');
        $payrollCategory = PayrollCategory::from($validated['payroll_category']);
        $result = $exporter->export($companyId, $payrollCategory);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importPreview(
        ImportContractsRequest $request,
        ContractImportOrchestrator $orchestrator,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $result = $orchestrator->preview(
                $companyId,
                PayrollCategory::from($request->string('payroll_category')->toString()),
                $request->file('file'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }

    public function import(
        ImportContractsRequest $request,
        ContractImportOrchestrator $orchestrator,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $result = $orchestrator->execute(
                $companyId,
                PayrollCategory::from($request->string('payroll_category')->toString()),
                $request->file('file'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.contracts')
            ->with(
                'success',
                "Imported {$result['imported']} contract(s). Skipped {$result['skipped']} row(s).",
            );
    }
}
