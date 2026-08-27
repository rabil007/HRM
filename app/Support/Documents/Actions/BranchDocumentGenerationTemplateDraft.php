<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplateStorage;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class BranchDocumentGenerationTemplateDraft
{
    /**
     * Return the existing Draft version or transactional branch a new Draft version.
     */
    public function handle(DocumentGenerationTemplate $template, ?int $userId = null): DocumentGenerationTemplateVersion
    {
        $newPdfPath = null;
        $companyId = (int) $template->company_id;

        try {
            return DB::transaction(function () use ($template, $userId, &$newPdfPath, &$companyId): DocumentGenerationTemplateVersion {
                /** @var DocumentGenerationTemplate $locked */
                $locked = DocumentGenerationTemplate::query()
                    ->whereKey($template->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $companyId = (int) $locked->company_id;

                // 1. Enforce at most one Draft per template
                $existingDraft = $locked->draftVersion;
                if ($existingDraft !== null) {
                    return $existingDraft;
                }

                // 2. Allocate next version number
                $nextVersion = ((int) $locked->versions()->max('version')) + 1;
                if ($nextVersion < 1) {
                    $nextVersion = 1;
                }

                // 3. Resolve source version to branch from
                $sourceVersion = $locked->publishedVersion ?? $locked->versions()->latest('version')->first();

                $content = null;
                $originalName = null;
                $sizeBytes = null;
                $pageCount = null;
                $placementConfig = null;

                if ($locked->isContent()) {
                    $content = $sourceVersion?->content ?? (string) $locked->content;
                } elseif ($locked->isPdfOverlay() && $sourceVersion?->source_pdf_path) {
                    // Physically copy source PDF to ensure complete immutability of published file
                    $newPdfPath = DocumentTemplateStorage::copyPdf(
                        $sourceVersion->source_pdf_path,
                        $locked->company_id,
                    );

                    $originalName = $sourceVersion->source_pdf_original_name;
                    $sizeBytes = $sourceVersion->source_pdf_size_bytes;
                    $pageCount = $sourceVersion->source_pdf_page_count;
                    $placementConfig = $sourceVersion->placement_config;
                }

                $draft = DocumentGenerationTemplateVersion::query()->create([
                    'company_id' => $locked->company_id,
                    'document_generation_template_id' => $locked->id,
                    'version' => $nextVersion,
                    'status' => DocumentGenerationTemplateVersionStatus::Draft,
                    'content' => $content,
                    'source_pdf_path' => $newPdfPath,
                    'source_pdf_original_name' => $originalName,
                    'source_pdf_size_bytes' => $sizeBytes,
                    'source_pdf_page_count' => $pageCount,
                    'placement_config' => $placementConfig,
                    'published_at' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                activity('document_templates')
                    ->performedOn($locked)
                    ->causedBy($userId)
                    ->tap(function (Activity $activity) use ($companyId): void {
                        $activity->company_id = $companyId;
                    })
                    ->withProperties([
                        'action' => 'draft_version_created',
                        'template_id' => $locked->id,
                        'version' => $nextVersion,
                    ])
                    ->log("Created draft version {$nextVersion} for template {$locked->name}");

                return $draft;
            });
        } catch (Throwable $e) {
            // Filesystem cleanup compensation if DB creation/commit fails
            if ($newPdfPath !== null) {
                DocumentTemplateStorage::deletePdf($newPdfPath, $companyId);
            }

            throw $e;
        }
    }
}
