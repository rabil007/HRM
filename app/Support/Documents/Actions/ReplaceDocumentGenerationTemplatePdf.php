<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplatePdfValidator;
use App\Support\Documents\DocumentTemplateStorage;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class ReplaceDocumentGenerationTemplatePdf
{
    public function handle(
        DocumentGenerationTemplateVersion $version,
        UploadedFile $file,
        ?int $userId = null,
    ): DocumentGenerationTemplateVersion {
        // Validate and inspect uploaded PDF outside transaction
        $inspected = DocumentTemplatePdfValidator::validateAndInspect($file);

        // Store new PDF to local disk first
        $newPath = DocumentTemplateStorage::storePdf($file, $version->company_id);
        $oldPath = null;
        $companyId = $version->company_id;

        try {
            DB::transaction(function () use ($version, $newPath, $inspected, $userId, &$oldPath, &$companyId): void {
                // 1. Lock parent template
                /** @var DocumentGenerationTemplate $lockedTemplate */
                $lockedTemplate = DocumentGenerationTemplate::query()
                    ->whereKey($version->document_generation_template_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 2. Lock target version
                /** @var DocumentGenerationTemplateVersion $lockedVersion */
                $lockedVersion = DocumentGenerationTemplateVersion::query()
                    ->whereKey($version->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedVersion->document_generation_template_id !== (int) $lockedTemplate->id
                    || (int) $lockedVersion->company_id !== (int) $lockedTemplate->company_id
                ) {
                    throw new DomainException('Template version does not match parent template.');
                }

                if (! $lockedVersion->isDraft()) {
                    throw new DomainException('Published or archived template versions cannot be edited.');
                }

                if (! $lockedTemplate->isPdfOverlay()) {
                    throw new DomainException('Cannot replace PDF on a content template.');
                }

                $companyId = (int) $lockedTemplate->company_id;
                $oldPath = $lockedVersion->source_pdf_path;

                $lockedVersion->source_pdf_path = $newPath;
                $lockedVersion->source_pdf_original_name = $inspected['original_name'];
                $lockedVersion->source_pdf_size_bytes = $inspected['size_bytes'];
                $lockedVersion->source_pdf_page_count = $inspected['page_count'];
                $lockedVersion->placement_config = null; // Clear placements on replacement
                $lockedVersion->updated_by = $userId;
                $lockedVersion->save();

                activity('document_templates')
                    ->performedOn($lockedTemplate)
                    ->causedBy($userId)
                    ->tap(function (Activity $activity) use ($companyId): void {
                        $activity->company_id = $companyId;
                    })
                    ->withProperties([
                        'action' => 'template_pdf_replaced',
                        'template_id' => $lockedTemplate->id,
                        'version' => $lockedVersion->version,
                        'page_count' => $inspected['page_count'],
                    ])
                    ->log("Replaced PDF for template {$lockedTemplate->name} (v{$lockedVersion->version})");
            });
        } catch (Throwable $e) {
            // DB transaction failed: delete NEW PDF and leave OLD PDF untouched
            DocumentTemplateStorage::deletePdf($newPath, $companyId);

            throw $e;
        }

        // DB transaction succeeded: safely attempt to delete old physical file
        if ($oldPath !== null && $oldPath !== $newPath) {
            try {
                DocumentTemplateStorage::deletePdf($oldPath, $companyId);
            } catch (Throwable $cleanupError) {
                Log::error('Failed to clean up old template PDF after replacement', [
                    'old_path' => $oldPath,
                    'company_id' => $companyId,
                    'error' => $cleanupError->getMessage(),
                ]);
            }
        }

        return $version->fresh();
    }
}
