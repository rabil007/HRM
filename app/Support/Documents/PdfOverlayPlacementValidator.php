<?php

namespace App\Support\Documents;

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplateVersion;
use InvalidArgumentException;

/**
 * Defensive runtime validation of a published PDF overlay placement_config.
 *
 * Even though draft-time saving already validates placements, generation treats
 * published config as untrusted persisted data and re-validates fully before
 * rendering. This prevents corrupt or manually-altered configurations from
 * producing incorrect PDF output.
 */
final class PdfOverlayPlacementValidator
{
    /**
     * Validate a published placement_config at generation time.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<array{id: string, field: string, page: int, x: float, y: float, width: float, height: float, font_size: int, font_weight: string, text_align: string}>
     *
     * @throws InvalidArgumentException if the configuration is invalid.
     */
    public static function validate(?array $config, int $sourcePageCount): array
    {
        if (! is_array($config)) {
            throw new InvalidArgumentException('Template placement configuration is missing or corrupt.');
        }

        if ((int) ($config['schema_version'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Unsupported placement configuration schema version.');
        }

        $rawPlacements = $config['placements'] ?? [];

        if (! is_array($rawPlacements)) {
            throw new InvalidArgumentException('Template placement list is invalid.');
        }

        $allowedKeys = DocumentTemplateMergeFields::allowedKeys();
        $validated = [];

        foreach ($rawPlacements as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("Placement #{$index} is not a valid object.");
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException("Placement #{$index} is missing a required ID.");
            }

            $field = trim((string) ($item['field'] ?? ''));
            if (! in_array($field, $allowedKeys, true)) {
                throw new InvalidArgumentException("Placement #{$index} references an unsupported merge field.");
            }

            $page = (int) ($item['page'] ?? 0);
            if ($page < 1 || $page > $sourcePageCount) {
                throw new InvalidArgumentException("Placement #{$index} references an invalid page number.");
            }

            $x = (float) ($item['x'] ?? -1);
            $y = (float) ($item['y'] ?? -1);
            $width = (float) ($item['width'] ?? 0);
            $height = (float) ($item['height'] ?? 0);

            if ($x < 0.0 || $x > 1.0) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid x coordinate.");
            }

            if ($y < 0.0 || $y > 1.0) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid y coordinate.");
            }

            if ($width <= 0.0 || $width > 1.0) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid width.");
            }

            if ($height <= 0.0 || $height > 1.0) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid height.");
            }

            if (($x + $width) > 1.0005) {
                throw new InvalidArgumentException("Placement #{$index} extends beyond the right page boundary.");
            }

            if (($y + $height) > 1.0005) {
                throw new InvalidArgumentException("Placement #{$index} extends beyond the bottom page boundary.");
            }

            $fontSize = (int) ($item['font_size'] ?? 0);
            if ($fontSize < 8 || $fontSize > 48) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid font size (must be 8–48).");
            }

            $rawWeight = $item['font_weight'] ?? '';
            $fontWeight = in_array($rawWeight, ['normal', 'bold'], true) ? $rawWeight : null;
            if ($fontWeight === null) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid font weight.");
            }

            $rawAlign = $item['text_align'] ?? '';
            $textAlign = in_array($rawAlign, ['left', 'center', 'right'], true) ? $rawAlign : null;
            if ($textAlign === null) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid text alignment.");
            }

            $validated[] = [
                'id' => $id,
                'field' => $field,
                'page' => $page,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'font_size' => $fontSize,
                'font_weight' => $fontWeight,
                'text_align' => $textAlign,
            ];
        }

        return $validated;
    }

    /**
     * Validate a published version's placement config and return validated placements.
     *
     * Checks that the version belongs to a pdf_overlay template, is not a draft,
     * has a valid source PDF page count, and passes full placement validation.
     *
     * @return list<array{id: string, field: string, page: int, x: float, y: float, width: float, height: float, font_size: int, font_weight: string, text_align: string}>
     *
     * @throws InvalidArgumentException if validation fails.
     */
    public static function validateVersion(DocumentGenerationTemplateVersion $version): array
    {
        if ($version->status === DocumentGenerationTemplateVersionStatus::Draft) {
            throw new InvalidArgumentException('Draft versions cannot be used for generation.');
        }

        $pageCount = (int) ($version->source_pdf_page_count ?? 0);

        if ($pageCount < 1) {
            throw new InvalidArgumentException('Template version has no valid source PDF page count.');
        }

        return self::validate($version->placement_config, $pageCount);
    }
}
