<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplateMergeFields;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

final class SaveDocumentGenerationTemplatePlacements
{
    /**
     * @param  list<array<string, mixed>>  $placements
     */
    public function handle(
        DocumentGenerationTemplateVersion $version,
        array $placements,
        ?int $userId = null,
    ): DocumentGenerationTemplateVersion {
        return DB::transaction(function () use ($version, $placements, $userId): DocumentGenerationTemplateVersion {
            /** @var DocumentGenerationTemplate $lockedTemplate */
            $lockedTemplate = DocumentGenerationTemplate::query()
                ->whereKey($version->document_generation_template_id)
                ->lockForUpdate()
                ->firstOrFail();

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
                throw new DomainException('Cannot save placements for a content template.');
            }

            $pageCount = (int) ($lockedVersion->source_pdf_page_count ?? 1);
            $allowedKeys = DocumentTemplateMergeFields::allowedKeys();
            $seenIds = [];
            $validated = [];

            foreach ($placements as $index => $item) {
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    $id = (string) Str::uuid();
                }

                if (isset($seenIds[$id])) {
                    throw ValidationException::withMessages([
                        "placements.{$index}.id" => 'Duplicate placement ID detected.',
                    ]);
                }
                $seenIds[$id] = true;

                $field = trim((string) ($item['field'] ?? ''));
                if (! in_array($field, $allowedKeys, true)) {
                    throw ValidationException::withMessages([
                        "placements.{$index}.field" => "The merge field '{$field}' is not supported.",
                    ]);
                }

                $page = (int) ($item['page'] ?? 1);
                if ($page < 1 || $page > $pageCount) {
                    throw ValidationException::withMessages([
                        "placements.{$index}.page" => "Invalid page {$page}. Template PDF has {$pageCount} pages.",
                    ]);
                }

                $x = (float) ($item['x'] ?? 0);
                $y = (float) ($item['y'] ?? 0);
                $width = (float) ($item['width'] ?? 0);
                $height = (float) ($item['height'] ?? 0);

                if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
                    throw ValidationException::withMessages([
                        "placements.{$index}" => 'Coordinates must be normalized between 0.0 and 1.0.',
                    ]);
                }

                if ($width <= 0 || $width > 1 || $height <= 0 || $height > 1) {
                    throw ValidationException::withMessages([
                        "placements.{$index}" => 'Field width and height must be positive and at most 1.0.',
                    ]);
                }

                // Small epsilon to accommodate floating point rounding in browser
                if (($x + $width) > 1.0005 || ($y + $height) > 1.0005) {
                    throw ValidationException::withMessages([
                        "placements.{$index}" => 'Placement exceeds the boundaries of the PDF page.',
                    ]);
                }

                $fontSize = (int) ($item['font_size'] ?? 12);
                if ($fontSize < 8 || $fontSize > 48) {
                    $fontSize = 12;
                }

                $fontWeight = ($item['font_weight'] ?? 'normal') === 'bold'
                    ? 'bold'
                    : 'normal';

                $rawTextAlign = $item['text_align'] ?? 'left';
                $textAlign = in_array($rawTextAlign, ['left', 'center', 'right'], true)
                    ? $rawTextAlign
                    : 'left';

                $validated[] = [
                    'id' => $id,
                    'field' => $field,
                    'page' => $page,
                    'x' => round($x, 6),
                    'y' => round($y, 6),
                    'width' => round($width, 6),
                    'height' => round($height, 6),
                    'font_size' => $fontSize,
                    'font_weight' => $fontWeight,
                    'text_align' => $textAlign,
                ];
            }

            $lockedVersion->placement_config = [
                'schema_version' => 1,
                'placements' => $validated,
            ];
            $lockedVersion->updated_by = $userId;
            $lockedVersion->save();

            $companyId = (int) $lockedTemplate->company_id;
            activity('document_templates')
                ->performedOn($lockedTemplate)
                ->causedBy($userId)
                ->tap(function (Activity $activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->withProperties([
                    'action' => 'template_pdf_placements_updated',
                    'template_id' => $lockedTemplate->id,
                    'version' => $lockedVersion->version,
                    'placement_count' => count($validated),
                    'page_count' => $pageCount,
                ])
                ->log("Updated PDF placements for template {$lockedTemplate->name} (v{$lockedVersion->version})");

            return $lockedVersion;
        });
    }
}
