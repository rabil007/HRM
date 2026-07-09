<?php

namespace App\Support\BulkDocuments;

use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;
use InvalidArgumentException;

final class BulkDocumentSignaturePlacementService
{
    private const PAGE_WIDTH_MM = 210.0;

    private const PAGE_HEIGHT_MM = 297.0;

    public function __construct(private SettingService $settings) {}

    /**
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }|null
     */
    public function resolve(string $documentTypeKey): ?array
    {
        if (! $this->supportsDocumentType($documentTypeKey)) {
            return null;
        }

        $stored = $this->readStoredConfig($documentTypeKey);

        if ($stored !== null) {
            return $stored;
        }

        return SalaryDeclarationSignaturePlacements::forDocumentType($documentTypeKey);
    }

    /**
     * @param  array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }  $config
     */
    public function save(string $documentTypeKey, array $config): void
    {
        $settingKey = $this->settingKeyFor($documentTypeKey);

        if ($settingKey === null) {
            throw new InvalidArgumentException("Unsupported document type [{$documentTypeKey}].");
        }

        $this->settings->set($settingKey, json_encode($config, JSON_THROW_ON_ERROR), 'json');
    }

    public function resetToDefaults(string $documentTypeKey): void
    {
        $settingKey = $this->settingKeyFor($documentTypeKey);

        if ($settingKey === null) {
            throw new InvalidArgumentException("Unsupported document type [{$documentTypeKey}].");
        }

        $this->settings->set($settingKey, null, 'json');
    }

    /**
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }
     */
    public function fromEditorRects(
        float $signatureLeft,
        float $signatureTop,
        float $signatureWidth,
        float $signatureHeight,
        float $dateLeft,
        float $dateTop,
        float $dateWidth,
        float $dateHeight,
        float $canvasWidth,
        float $canvasHeight,
        int $page = 1,
    ): array {
        $overlay = [
            'left' => $this->toPercent($signatureLeft, $canvasWidth),
            'top' => $this->toPercent($signatureTop, $canvasHeight),
            'width' => $this->toPercent($signatureWidth, $canvasWidth),
            'height' => $this->toPercent($signatureHeight, $canvasHeight),
        ];

        $enImage = [
            'type' => 'image',
            'x' => $this->toMmX($signatureLeft, $canvasWidth),
            'y' => $this->toMmY($signatureTop, $canvasHeight),
            'w' => $this->toMmX($signatureWidth, $canvasWidth),
            'h' => $this->toMmY($signatureHeight, $canvasHeight),
        ];

        $arImage = [
            'type' => 'image',
            'x' => $this->mirrorMmX($enImage['x'], $enImage['w']),
            'y' => $enImage['y'],
            'w' => $enImage['w'],
            'h' => $enImage['h'],
        ];

        $enDate = [
            'type' => 'date',
            'x' => $this->toMmX($dateLeft, $canvasWidth),
            'y' => $this->toMmY($dateTop + $dateHeight, $canvasHeight),
        ];

        $dateWidthMm = $this->toMmX($dateWidth, $canvasWidth);
        $arDate = [
            'type' => 'date',
            'x' => $this->mirrorMmX($enDate['x'], $dateWidthMm),
            'y' => $enDate['y'],
        ];

        return [
            'page' => $page,
            'overlay' => $overlay,
            'stamps' => [$enImage, $arImage, $enDate, $arDate],
        ];
    }

    /**
     * @param  array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }  $config
     * @return array{
     *     signature: array{left: float, top: float, width: float, height: float},
     *     date: array{left: float, top: float, width: float, height: float}
     * }
     */
    public function editorRectsFromConfig(
        array $config,
        float $canvasWidth,
        float $canvasHeight,
    ): array {
        $overlay = $config['overlay'];
        $signature = [
            'left' => $this->fromPercent($overlay['left'], $canvasWidth),
            'top' => $this->fromPercent($overlay['top'], $canvasHeight),
            'width' => $this->fromPercent($overlay['width'], $canvasWidth),
            'height' => $this->fromPercent($overlay['height'], $canvasHeight),
        ];

        $enDate = collect($config['stamps'])->first(
            fn (array $stamp): bool => $stamp['type'] === 'date',
        );

        $dateWidth = max($signature['width'] * 0.6, 40.0);
        $dateHeight = max($signature['height'] * 0.5, 16.0);

        if ($enDate === null) {
            return [
                'signature' => $signature,
                'date' => [
                    'left' => $signature['left'],
                    'top' => $signature['top'] + $signature['height'] + 8,
                    'width' => $dateWidth,
                    'height' => $dateHeight,
                ],
            ];
        }

        $dateLeft = $this->fromMmX((float) $enDate['x'], $canvasWidth);
        $dateBottom = $this->fromMmY((float) $enDate['y'], $canvasHeight);

        return [
            'signature' => $signature,
            'date' => [
                'left' => $dateLeft,
                'top' => max(0, $dateBottom - $dateHeight),
                'width' => $dateWidth,
                'height' => $dateHeight,
            ],
        ];
    }

    public function defaults(string $documentTypeKey): ?array
    {
        return SalaryDeclarationSignaturePlacements::forDocumentType($documentTypeKey);
    }

    public function supportsDocumentType(string $documentTypeKey): bool
    {
        return $this->settingKeyFor($documentTypeKey) !== null;
    }

    private function settingKeyFor(string $documentTypeKey): ?string
    {
        return match ($documentTypeKey) {
            SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY => SettingKey::BulkDocumentSignaturePlacementSalaryDeclaration,
            default => null,
        };
    }

    /**
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }|null
     */
    private function readStoredConfig(string $documentTypeKey): ?array
    {
        $settingKey = $this->settingKeyFor($documentTypeKey);

        if ($settingKey === null) {
            return null;
        }

        $raw = $this->settings->get($settingKey);

        if (! filled($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['overlay'], $decoded['stamps'])) {
            return null;
        }

        return $this->normalizeConfig($decoded);
    }

    /**
     * @param  array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }  $config
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }
     */
    private function normalizeConfig(array $config): array
    {
        $config['page'] = (int) $config['page'];
        $config['stamps'] = array_map(function (array $stamp): array {
            $stamp['x'] = (float) $stamp['x'];
            $stamp['y'] = (float) $stamp['y'];

            if (isset($stamp['w'])) {
                $stamp['w'] = (float) $stamp['w'];
            }

            if (isset($stamp['h'])) {
                $stamp['h'] = (float) $stamp['h'];
            }

            return $stamp;
        }, $config['stamps']);

        return $config;
    }

    private function toPercent(float $value, float $total): string
    {
        if ($total <= 0) {
            return '0%';
        }

        return round(($value / $total) * 100, 4).'%';
    }

    private function fromPercent(string $value, float $total): float
    {
        $numeric = (float) rtrim(trim($value), '%');

        return ($numeric / 100) * $total;
    }

    private function toMmX(float $pixels, float $canvasWidth): float
    {
        if ($canvasWidth <= 0) {
            return 0.0;
        }

        return round(($pixels / $canvasWidth) * self::PAGE_WIDTH_MM, 2);
    }

    private function toMmY(float $pixels, float $canvasHeight): float
    {
        if ($canvasHeight <= 0) {
            return 0.0;
        }

        return round(($pixels / $canvasHeight) * self::PAGE_HEIGHT_MM, 2);
    }

    private function fromMmX(float $mm, float $canvasWidth): float
    {
        return ($mm / self::PAGE_WIDTH_MM) * $canvasWidth;
    }

    private function fromMmY(float $mm, float $canvasHeight): float
    {
        return ($mm / self::PAGE_HEIGHT_MM) * $canvasHeight;
    }

    private function mirrorMmX(float $x, float $width): float
    {
        return round(self::PAGE_WIDTH_MM - $x - $width, 2);
    }
}
