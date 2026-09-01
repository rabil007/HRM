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
     * @return array{schema_version: int, placements: list<array<string, mixed>>}
     */
    public static function emptyConfig(): array
    {
        return [
            'schema_version' => 2,
            'placements' => [],
        ];
    }

    public static function normalizeFontFamily(mixed $value): string
    {
        return $value === 'serif' ? 'serif' : 'sans';
    }

    public static function cssFontFamily(string $family): string
    {
        return self::normalizeFontFamily($family) === 'serif'
            ? "'Times New Roman', Times, 'DejaVu Serif', serif"
            : "Arial, Helvetica, 'DejaVu Sans', sans-serif";
    }

    public const DEFAULT_FONT_COLOR = '#000000';

    /**
     * @return 'top'|'middle'|'baseline'
     */
    public static function normalizeVerticalAlign(mixed $value, string $placementType = 'field'): string
    {
        if (is_string($value) && in_array($value, ['top', 'middle', 'baseline'], true)) {
            return $value;
        }

        return $placementType === 'text' ? 'top' : 'middle';
    }

    /**
     * @return 'top'|'middle'|'baseline'
     */
    public static function parseVerticalAlign(mixed $value, string $placementType, int $index): string
    {
        if ($value === null || $value === '') {
            return self::normalizeVerticalAlign(null, $placementType);
        }

        if (! is_string($value) || ! in_array($value, ['top', 'middle', 'baseline'], true)) {
            throw new InvalidArgumentException("Placement #{$index} has an invalid vertical alignment.");
        }

        return $value;
    }

    public static function cssVerticalAlign(string $align): string
    {
        return match ($align) {
            'top' => 'flex-start',
            'baseline' => 'flex-end',
            default => 'center',
        };
    }

    public static function normalizeFontColor(mixed $value): ?string
    {
        $color = strtolower(trim((string) $value));

        if ($color === '') {
            return self::DEFAULT_FONT_COLOR;
        }

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    /**
     * Normalize persisted placement config for validation.
     *
     * Legacy published versions may store null to represent zero placements.
     * Malformed non-null structures must still be rejected.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{schema_version: int, placements: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(?array $config): array
    {
        if ($config === null) {
            return self::emptyConfig();
        }

        if ($config === []) {
            throw new InvalidArgumentException('Template placement configuration is missing or corrupt.');
        }

        if (! array_key_exists('schema_version', $config)) {
            throw new InvalidArgumentException('Unsupported placement configuration schema version.');
        }

        return $config;
    }

    /**
     * Validate a published placement_config at generation time.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<array{id: string, type: string, page: int, x: float, y: float, width: float, height: float, font_size: int, font_weight: string, text_align: string, vertical_align: string, font_family: string, field?: string, text_content?: string}>
     *
     * @throws InvalidArgumentException if the configuration is invalid.
     */
    public static function validate(?array $config, int $sourcePageCount): array
    {
        $config = self::normalize($config);
        $schemaVersion = (int) ($config['schema_version'] ?? 0);

        if (! in_array($schemaVersion, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported placement configuration schema version.');
        }

        $rawPlacements = $config['placements'] ?? [];

        if (! is_array($rawPlacements)) {
            throw new InvalidArgumentException('Template placement list is invalid.');
        }

        $allowedKeys = DocumentTemplateMergeFields::allowedKeys();
        $validated = [];
        $seenIds = [];

        foreach ($rawPlacements as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("Placement #{$index} is not a valid object.");
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException("Placement #{$index} is missing a required ID.");
            }

            if (isset($seenIds[$id])) {
                throw new InvalidArgumentException("Placement #{$index} uses a duplicate ID.");
            }
            $seenIds[$id] = true;

            $type = self::parsePlacementType($item, $schemaVersion, $index);

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

            $rawFamily = $item['font_family'] ?? 'sans';
            $fontFamily = in_array($rawFamily, ['sans', 'serif'], true) ? $rawFamily : null;
            if ($fontFamily === null) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid font family.");
            }

            $fontColor = self::normalizeFontColor($item['font_color'] ?? self::DEFAULT_FONT_COLOR);
            if ($fontColor === null) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid font color.");
            }

            $verticalAlign = self::parseVerticalAlign($item['vertical_align'] ?? null, $type, $index);

            if ($type === 'text') {
                $textContent = trim((string) ($item['text_content'] ?? ''));
                if ($textContent === '') {
                    throw new InvalidArgumentException("Placement #{$index} (static text) is missing text_content.");
                }
                if (strlen($textContent) > 500) {
                    throw new InvalidArgumentException("Placement #{$index} (static text) text_content exceeds 500 characters.");
                }
                $validated[] = [
                    'id' => $id,
                    'type' => 'text',
                    'text_content' => $textContent,
                    'page' => $page,
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height,
                    'font_size' => $fontSize,
                    'font_weight' => $fontWeight,
                    'text_align' => $textAlign,
                    'vertical_align' => $verticalAlign,
                    'font_family' => $fontFamily,
                    'font_color' => $fontColor,
                ];
            } else {
                $field = trim((string) ($item['field'] ?? ''));
                if (! in_array($field, $allowedKeys, true)) {
                    throw new InvalidArgumentException("Placement #{$index} references an unsupported merge field.");
                }
                $validated[] = [
                    'id' => $id,
                    'type' => 'field',
                    'field' => $field,
                    'page' => $page,
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height,
                    'font_size' => $fontSize,
                    'font_weight' => $fontWeight,
                    'text_align' => $textAlign,
                    'vertical_align' => $verticalAlign,
                    'font_family' => $fontFamily,
                    'font_color' => $fontColor,
                ];
            }
        }

        return $validated;
    }

    /**
     * Validate a published version's placement config and return validated placements.
     *
     * @return list<array{id: string, type: string, page: int, x: float, y: float, width: float, height: float, font_size: int, font_weight: string, text_align: string, vertical_align: string, font_family: string, field?: string, text_content?: string}>
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

    /**
     * Resolve a placement type for the given schema.
     *
     * Schema v1: a missing type continues to mean `field`. Schema v2 requires an
     * explicit `field` or `text` value; missing, empty, and unknown types are rejected.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws InvalidArgumentException
     */
    public static function parsePlacementType(array $item, int $schemaVersion, int $index): string
    {
        $hasType = array_key_exists('type', $item);
        $rawType = $hasType ? $item['type'] : null;

        if ($schemaVersion === 1) {
            if (! $hasType || $rawType === null || $rawType === '') {
                return 'field';
            }

            if (! is_string($rawType) || ! in_array($rawType, ['field', 'text'], true)) {
                throw new InvalidArgumentException("Placement #{$index} has an invalid type.");
            }

            return $rawType;
        }

        if ($schemaVersion !== 2) {
            throw new InvalidArgumentException('Unsupported placement configuration schema version.');
        }

        if (! $hasType || $rawType === null || $rawType === '') {
            throw new InvalidArgumentException("Placement #{$index} is missing a required type.");
        }

        if (! is_string($rawType) || ! in_array($rawType, ['field', 'text'], true)) {
            throw new InvalidArgumentException("Placement #{$index} has an invalid type.");
        }

        return $rawType;
    }
}
