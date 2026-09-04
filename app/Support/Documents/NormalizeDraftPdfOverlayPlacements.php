<?php

namespace App\Support\Documents;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class NormalizeDraftPdfOverlayPlacements
{
    /**
     * @param  list<array<string, mixed>>  $rawPlacements
     * @return list<array<string, mixed>>
     */
    public static function handle(array $rawPlacements, int $pageCount): array
    {
        $allowedKeys = DocumentTemplateMergeFields::allowedKeys();
        $seenIds = [];
        $validatedPlacements = [];

        foreach ($rawPlacements as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "placements.{$index}" => 'Placement is not a valid object.',
                ]);
            }

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

            try {
                $type = PdfOverlayPlacementValidator::parsePlacementType($item, 2, $index);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    "placements.{$index}.type" => $e->getMessage(),
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
            $verticalAlign = PdfOverlayPlacementValidator::normalizeVerticalAlign(
                $item['vertical_align'] ?? null,
                $type,
            );
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
                    'vertical_align' => $verticalAlign,
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
                    'vertical_align' => $verticalAlign,
                    'font_family' => $fontFamily,
                    'font_color' => $fontColor,
                ];
            }
        }

        return $validatedPlacements;
    }
}
