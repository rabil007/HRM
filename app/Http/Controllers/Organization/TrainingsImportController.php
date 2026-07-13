<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Trainings\ImportTrainingsRequest;
use App\Support\EmployeeTrainings\TrainingImportOrchestrator;
use App\Support\EmployeeTrainings\TrainingImportTemplateExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TrainingsImportController extends Controller
{
    public function importTemplate(Request $request, TrainingImportTemplateExporter $exporter)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $result = $exporter->export($companyId);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importPreview(
        ImportTrainingsRequest $request,
        TrainingImportOrchestrator $orchestrator,
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
        ImportTrainingsRequest $request,
        TrainingImportOrchestrator $orchestrator,
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
            ->route('organization.training')
            ->with(
                'success',
                "Imported {$result['imported']} training row(s). Skipped {$result['skipped']} row(s).",
            );
    }
}
