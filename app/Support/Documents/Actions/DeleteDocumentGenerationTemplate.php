<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Support\Documents\DocumentTemplateStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeleteDocumentGenerationTemplate
{
    public function handle(DocumentGenerationTemplate $template): void
    {
        if ($template->instances()->exists() || $template->generationRuns()->exists()) {
            throw ValidationException::withMessages([
                'template' => 'This template cannot be deleted because document generation history exists. Deactivate the template instead.',
            ]);
        }

        $companyId = (int) $template->company_id;
        $expectedPrefix = DocumentTemplateStorage::directory($companyId).'/';
        $pdfPaths = [];

        foreach ($template->versions as $version) {
            if ($version->source_pdf_path && str_starts_with($version->source_pdf_path, $expectedPrefix)) {
                $pdfPaths[] = $version->source_pdf_path;
            }
        }

        DB::transaction(function () use ($template): void {
            // Break the circular FK (template.published_version_id <-> versions) before cascade delete.
            $template->published_version_id = null;
            $template->saveQuietly();
            $template->delete();
        });

        foreach ($pdfPaths as $path) {
            try {
                DocumentTemplateStorage::deletePdf($path, $companyId);
            } catch (Throwable $e) {
                Log::error('Failed to clean up orphaned template PDF after deletion', [
                    'path' => $path,
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
