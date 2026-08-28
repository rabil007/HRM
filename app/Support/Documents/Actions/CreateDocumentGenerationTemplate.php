<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\DocumentTemplatePdfValidator;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\PdfOverlayPlacementValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateDocumentGenerationTemplate
{
    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     document_type_id?: ?int,
     *     template_format?: string|DocumentGenerationTemplateFormat,
     *     content?: ?string,
     *     file?: ?UploadedFile,
     *     status?: string|DocumentGenerationTemplateStatus
     * }  $data
     */
    public function handle(int $companyId, array $data, ?User $actor = null): DocumentGenerationTemplate
    {
        $format = $data['template_format'] ?? DocumentGenerationTemplateFormat::Content;
        if (is_string($format)) {
            $format = DocumentGenerationTemplateFormat::from($format);
        }

        $status = DocumentGenerationTemplateStatus::Draft;
        $content = isset($data['content']) ? (string) $data['content'] : '';
        $storedPdfPath = null;
        $pdfInspected = null;

        if ($format->isPdfOverlay()) {
            if (! isset($data['file']) || ! $data['file'] instanceof UploadedFile) {
                throw new \InvalidArgumentException('A PDF file is required for PDF template creation.');
            }

            $pdfInspected = DocumentTemplatePdfValidator::validateAndInspect($data['file']);
            $storedPdfPath = DocumentTemplateStorage::storePdf($data['file'], $companyId);
            $content = ''; // Keep parent column valid without storing dummy text
        }

        try {
            return DB::transaction(function () use ($companyId, $data, $format, $status, $content, $storedPdfPath, $pdfInspected, $actor): DocumentGenerationTemplate {
                /** @var DocumentGenerationTemplate $template */
                $template = DocumentGenerationTemplate::query()->create([
                    'company_id' => $companyId,
                    'name' => trim($data['name']),
                    'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
                    'document_type_id' => ! empty($data['document_type_id']) ? (int) $data['document_type_id'] : null,
                    'template_format' => $format,
                    'content' => $content,
                    'status' => $status,
                    'published_version_id' => null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);

                DocumentGenerationTemplateVersion::query()->create([
                    'company_id' => $companyId,
                    'document_generation_template_id' => $template->id,
                    'version' => 1,
                    'status' => DocumentGenerationTemplateVersionStatus::Draft,
                    'content' => $format->isContent() ? $content : null,
                    'source_pdf_path' => $storedPdfPath,
                    'source_pdf_original_name' => $pdfInspected['original_name'] ?? null,
                    'source_pdf_size_bytes' => $pdfInspected['size_bytes'] ?? null,
                    'source_pdf_page_count' => $pdfInspected['page_count'] ?? null,
                    'placement_config' => $format->isPdfOverlay()
                        ? PdfOverlayPlacementValidator::emptyConfig()
                        : null,
                    'published_at' => null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);

                return $template->fresh(['publishedVersion', 'draftVersion']);
            });
        } catch (Throwable $e) {
            if ($storedPdfPath !== null) {
                DocumentTemplateStorage::deletePdf($storedPdfPath, $companyId);
            }

            throw $e;
        }
    }
}
