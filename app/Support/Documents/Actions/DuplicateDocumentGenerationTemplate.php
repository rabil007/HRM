<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\DocumentTemplateStorage;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DuplicateDocumentGenerationTemplate
{
    public function handle(DocumentGenerationTemplate $template, ?User $actor = null): DocumentGenerationTemplate
    {
        $uniqueName = $this->resolveUniqueCopyName($template);
        $sourceVersion = $template->publishedVersion ?? $template->draftVersion ?? $template->versions()->latest('version')->first();

        $newPdfPath = null;
        if ($template->isPdfOverlay() && $sourceVersion?->source_pdf_path) {
            $newPdfPath = DocumentTemplateStorage::copyPdf(
                $sourceVersion->source_pdf_path,
                $template->company_id,
            );
        }

        try {
            return DB::transaction(function () use ($template, $sourceVersion, $uniqueName, $newPdfPath, $actor): DocumentGenerationTemplate {
                $copy = DocumentGenerationTemplate::query()->create([
                    'company_id' => $template->company_id,
                    'name' => $uniqueName,
                    'description' => $template->description,
                    'document_type_id' => $template->document_type_id,
                    'template_format' => $template->template_format,
                    'content' => $template->content,
                    'status' => DocumentGenerationTemplateStatus::Draft,
                    'published_version_id' => null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);

                DocumentGenerationTemplateVersion::query()->create([
                    'company_id' => $copy->company_id,
                    'document_generation_template_id' => $copy->id,
                    'version' => 1,
                    'status' => DocumentGenerationTemplateVersionStatus::Draft,
                    'content' => $template->isContent() ? ($sourceVersion?->content ?? $template->content) : null,
                    'source_pdf_path' => $newPdfPath,
                    'source_pdf_original_name' => $sourceVersion?->source_pdf_original_name,
                    'source_pdf_size_bytes' => $sourceVersion?->source_pdf_size_bytes,
                    'source_pdf_page_count' => $sourceVersion?->source_pdf_page_count,
                    'placement_config' => $sourceVersion?->placement_config,
                    'published_at' => null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);

                activity()
                    ->performedOn($copy)
                    ->causedBy($actor)
                    ->event('duplicated')
                    ->withProperties([
                        'source_id' => $template->id,
                        'source_name' => $template->name,
                        'name' => $copy->name,
                        'company_id' => $copy->company_id,
                    ])
                    ->log("Duplicated template '{$template->name}' as '{$copy->name}'");

                return $copy->fresh(['publishedVersion', 'draftVersion']);
            });
        } catch (Throwable $e) {
            if ($newPdfPath !== null) {
                DocumentTemplateStorage::deletePdf($newPdfPath, $template->company_id);
            }

            throw $e;
        }
    }

    private function resolveUniqueCopyName(DocumentGenerationTemplate $template): string
    {
        $baseName = $template->name;
        $candidate = "{$baseName} (Copy)";

        $counter = 1;
        while (DocumentGenerationTemplate::query()
            ->where('company_id', $template->company_id)
            ->where('name', $candidate)
            ->exists()
        ) {
            $counter++;
            $candidate = "{$baseName} (Copy {$counter})";
        }

        return $candidate;
    }
}
