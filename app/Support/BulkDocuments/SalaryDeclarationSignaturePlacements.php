<?php

namespace App\Support\BulkDocuments;

/**
 * Signature placement metadata for the salary declaration PDF (A4, single page, EN/AR columns).
 *
 * Coordinates are in millimeters from the top-left of the page (FPDF convention).
 * Overlay percentages are relative to the rendered PDF page canvas for the esign UI.
 */
final class SalaryDeclarationSignaturePlacements
{
    public const DOCUMENT_TYPE_KEY = 'salary_declaration';

    /**
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }
     */
    public static function config(): array
    {
        return [
            'page' => 1,
            'overlay' => [
                'left' => '8%',
                'top' => '76%',
                'width' => '38%',
                'height' => '9%',
            ],
            'stamps' => [
                [
                    'type' => 'image',
                    'x' => 24.0,
                    'y' => 248.0,
                    'w' => 68.0,
                    'h' => 14.0,
                ],
                [
                    'type' => 'image',
                    'x' => 118.0,
                    'y' => 248.0,
                    'w' => 68.0,
                    'h' => 14.0,
                ],
                [
                    'type' => 'date',
                    'x' => 24.0,
                    'y' => 268.0,
                ],
                [
                    'type' => 'date',
                    'x' => 118.0,
                    'y' => 268.0,
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }|null
     */
    public static function forDocumentType(string $documentTypeKey): ?array
    {
        return match ($documentTypeKey) {
            self::DOCUMENT_TYPE_KEY => self::config(),
            default => null,
        };
    }
}
