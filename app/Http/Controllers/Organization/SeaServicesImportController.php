<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\SeaServices\ImportSeaServicesRequest;
use App\Support\SeaServices\SeaServiceImportOrchestrator;
use App\Support\SeaServices\SeaServiceImportTemplateExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SeaServicesImportController extends Controller
{
    public function importTemplate(Request $request, SeaServiceImportTemplateExporter $exporter)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $result = $exporter->export($companyId);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importPreview(
        ImportSeaServicesRequest $request,
        SeaServiceImportOrchestrator $orchestrator,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $result = $orchestrator->preview(
                $companyId,
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
        ImportSeaServicesRequest $request,
        SeaServiceImportOrchestrator $orchestrator,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $result = $orchestrator->execute(
                $companyId,
                $request->file('file'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.sea-services')
            ->with(
                'success',
                "Imported {$result['imported']} sea service row(s). Skipped {$result['skipped']} row(s).",
            );
    }
}
