<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplatePdfValidator;
use App\Support\Documents\DocumentTemplateStorage;
use DomainException;
use Illuminate\Http\UploadedFile;
use Throwable;

final class ReplaceDocumentGenerationTemplatePdf
{
    public function handle(
        DocumentGenerationTemplateVersion $version,
        UploadedFile $file,
        ?int $userId = null,
    ): DocumentGenerationTemplateVersion {
        $version->assertEditable();

        $template = $version->template;
        if (! $template->isPdfOverlay()) {
            throw new DomainException('Cannot replace PDF on a content template.');
        }

        // Validate and inspect uploaded PDF
        $inspected = DocumentTemplatePdfValidator::validateAndInspect($file);

        // Store new PDF to local disk first
        $newPath = DocumentTemplateStorage::storePdf($file, $version->company_id);
        $oldPath = $version->source_pdf_path;

        try {
            $version->source_pdf_path = $newPath;
            $version->source_pdf_original_name = $inspected['original_name'];
            $version->source_pdf_size_bytes = $inspected['size_bytes'];
            $version->source_pdf_page_count = $inspected['page_count'];
            $version->placement_config = null; // Clear placements on replacement
            $version->updated_by = $userId;
            $version->save();

            // Safely delete old physical file after DB commit
            if ($oldPath !== null && $oldPath !== $newPath) {
                DocumentTemplateStorage::deletePdf($oldPath, $version->company_id);
            }

            activity('document_templates')
                ->performedOn($template)
                ->causedBy($userId)
                ->withProperties([
                    'action' => 'template_pdf_replaced',
                    'version' => $version->version,
                    'page_count' => $inspected['page_count'],
                ])
                ->log("Replaced PDF for template {$template->name} (v{$version->version})");

            return $version;
        } catch (Throwable $e) {
            // Filesystem compensation: delete newly uploaded file if DB operation fails
            DocumentTemplateStorage::deletePdf($newPath, $version->company_id);

            throw $e;
        }
    }
}
