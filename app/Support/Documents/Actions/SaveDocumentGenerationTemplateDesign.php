<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\PdfOverlayPlacementValidator;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

final class SaveDocumentGenerationTemplateDesign
{
    /**
     * @param  list<array<string, mixed>>  $rawPlacements
     * @param  array{schema_version?: mixed, placements?: mixed}  $rawSignatureConfig
     */
    public function handle(
        DocumentGenerationTemplateVersion $version,
        array $rawPlacements,
        array $rawSignatureConfig,
        ?int $userId = null,
    ): DocumentGenerationTemplateVersion {
        return DB::transaction(function () use ($version, $rawPlacements, $rawSignatureConfig, $userId): DocumentGenerationTemplateVersion {
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
                throw new DomainException('Cannot save design for a content template.');
            }

            $pageCount = (int) ($lockedVersion->source_pdf_page_count ?? 1);
            $allowedKeys = DocumentTemplateMergeFields::allowedKeys();

            // --- Validate placements ---
            $seenIds = [];
            $validatedPlacements = [];

            foreach ($rawPlacements as $index => $item) {
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

                $type = ($item['type'] ?? 'field') === 'text' ? 'text' : 'field';

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

                if (($x + $width) > 1.0005 || ($y + $height) > 1.0005) {
                    throw ValidationException::withMessages([
                        "placements.{$index}" => 'Placement exceeds the boundaries of the PDF page.',
                    ]);
                }

                $fontSize = (int) ($item['font_size'] ?? 12);
                if ($fontSize < 8 || $fontSize > 48) {
                    $fontSize = 12;
                }

                $fontWeight = ($item['font_weight'] ?? 'normal') === 'bold' ? 'bold' : 'normal';
                $rawTextAlign = $item['text_align'] ?? 'left';
                $textAlign = in_array($rawTextAlign, ['left', 'center', 'right'], true) ? $rawTextAlign : 'left';
                $fontFamily = PdfOverlayPlacementValidator::normalizeFontFamily($item['font_family'] ?? 'sans');
                $fontColor = PdfOverlayPlacementValidator::normalizeFontColor($item['font_color'] ?? PdfOverlayPlacementValidator::DEFAULT_FONT_COLOR);
                if ($fontColor === null) {
                    throw ValidationException::withMessages([
                        "placements.{$index}.font_color" => 'Font color must be a hex value such as #000000.',
                    ]);
                }

                if ($type === 'text') {
                    $textContent = trim((string) ($item['text_content'] ?? ''));
                    if ($textContent === '') {
                        throw ValidationException::withMessages([
                            "placements.{$index}.text_content" => 'Static text content cannot be empty.',
                        ]);
                    }
                    if (strlen($textContent) > 500) {
                        throw ValidationException::withMessages([
                            "placements.{$index}.text_content" => 'Static text content must not exceed 500 characters.',
                        ]);
                    }
                    $validatedPlacements[] = [
                        'id' => $id,
                        'type' => 'text',
                        'text_content' => $textContent,
                        'page' => $page,
                        'x' => round($x, 6),
                        'y' => round($y, 6),
                        'width' => round($width, 6),
                        'height' => round($height, 6),
                        'font_size' => $fontSize,
                        'font_weight' => $fontWeight,
                        'text_align' => $textAlign,
                        'font_family' => $fontFamily,
                        'font_color' => $fontColor,
                    ];
                } else {
                    $field = trim((string) ($item['field'] ?? ''));
                    if (! in_array($field, $allowedKeys, true)) {
                        throw ValidationException::withMessages([
                            "placements.{$index}.field" => "The merge field '{$field}' is not supported.",
                        ]);
                    }
                    $validatedPlacements[] = [
                        'id' => $id,
                        'type' => 'field',
                        'field' => $field,
                        'page' => $page,
                        'x' => round($x, 6),
                        'y' => round($y, 6),
                        'width' => round($width, 6),
                        'height' => round($height, 6),
                        'font_size' => $fontSize,
                        'font_weight' => $fontWeight,
                        'text_align' => $textAlign,
                        'font_family' => $fontFamily,
                        'font_color' => $fontColor,
                    ];
                }
            }

            // --- Validate signature placements ---
            try {
                $validatedSigConfig = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig(
                    $rawSignatureConfig,
                    $pageCount,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'signature_placement_config' => $e->getMessage(),
                ]);
            }

            $normalizedSignatures = array_map(
                function (array $placement): array {
                    $normalized = [
                        'id' => $placement['id'],
                        'type' => $placement['type'],
                        'role' => $placement['role'],
                        'page' => $placement['page'],
                        'x' => round($placement['x'], 6),
                        'y' => round($placement['y'], 6),
                        'width' => round($placement['width'], 6),
                        'height' => round($placement['height'], 6),
                        'required' => $placement['required'],
                    ];
                    if (isset($placement['slot_key'])) {
                        $normalized['slot_key'] = $placement['slot_key'];
                    }

                    return $normalized;
                },
                $validatedSigConfig['placements'],
            );

            // --- Single save: both configs in one DB write ---
            $lockedVersion->placement_config = [
                'schema_version' => 2,
                'placements' => $validatedPlacements,
            ];
            $lockedVersion->signature_placement_config = [
                'schema_version' => $validatedSigConfig['schema_version'],
                'placements' => $normalizedSignatures,
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
                    'action' => 'template_design_saved',
                    'template_id' => $lockedTemplate->id,
                    'version' => $lockedVersion->version,
                    'placement_count' => count($validatedPlacements),
                    'signature_count' => count($normalizedSignatures),
                    'page_count' => $pageCount,
                ])
                ->log("Design saved for template {$lockedTemplate->name} (v{$lockedVersion->version})");

            return $lockedVersion;
        });
    }
}
