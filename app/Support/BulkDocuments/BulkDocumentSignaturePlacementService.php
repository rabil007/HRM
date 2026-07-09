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
     * @param  array{left: float, top: float, width: float, height: float}  $signature
     * @param  array{left: float, top: float, width: float, height: float}  $date
     * @param  array{left: float, top: float, width: float, height: float}  $signatureAr
     * @param  array{left: float, top: float, width: float, height: float}  $dateAr
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }
     */
    public function fromEditorRects(
        array $signature,
        array $date,
        array $signatureAr,
        array $dateAr,
        float $canvasWidth,
        float $canvasHeight,
        int $page = 1,
    ): array {
        $overlay = [
            'left' => $this->toPercent((float) $signature['left'], $canvasWidth),
            'top' => $this->toPercent((float) $signature['top'], $canvasHeight),
            'width' => $this->toPercent((float) $signature['width'], $canvasWidth),
            'height' => $this->toPercent((float) $signature['height'], $canvasHeight),
        ];

        return [
            'page' => $page,
            'overlay' => $overlay,
            'stamps' => [
                $this->rectToImageStamp($signature, $canvasWidth, $canvasHeight),
                $this->rectToImageStamp($signatureAr, $canvasWidth, $canvasHeight),
                $this->rectToDateStamp($date, $canvasWidth, $canvasHeight),
                $this->rectToDateStamp($dateAr, $canvasWidth, $canvasHeight),
            ],
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
     *     date: array{left: float, top: float, width: float, height: float},
     *     signature_ar: array{left: float, top: float, width: float, height: float},
     *     date_ar: array{left: float, top: float, width: float, height: float}
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

        $imageStamps = array_values(array_filter(
            $config['stamps'],
            fn (array $stamp): bool => $stamp['type'] === 'image',
        ));
        $dateStamps = array_values(array_filter(
            $config['stamps'],
            fn (array $stamp): bool => $stamp['type'] === 'date',
        ));

        $defaultDateWidth = max($signature['width'] * 0.6, 40.0);
        $defaultDateHeight = max($signature['height'] * 0.5, 16.0);

        $signatureAr = isset($imageStamps[1])
            ? $this->imageStampToRect($imageStamps[1], $canvasWidth, $canvasHeight)
            : $this->mirrorRect($signature, $canvasWidth);

        $date = isset($dateStamps[0])
            ? $this->dateStampToRect($dateStamps[0], $defaultDateWidth, $defaultDateHeight, $canvasWidth, $canvasHeight)
            : [
                'left' => $signature['left'],
                'top' => $signature['top'] + $signature['height'] + 8,
                'width' => $defaultDateWidth,
                'height' => $defaultDateHeight,
            ];

        $dateAr = isset($dateStamps[1])
            ? $this->dateStampToRect($dateStamps[1], $defaultDateWidth, $defaultDateHeight, $canvasWidth, $canvasHeight)
            : $this->mirrorRect($date, $canvasWidth);

        return [
            'signature' => $signature,
            'date' => $date,
            'signature_ar' => $signatureAr,
            'date_ar' => $dateAr,
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

    /**
     * @param  array{left: float|int, top: float|int, width: float|int, height: float|int}  $rect
     * @return array{type: string, x: float, y: float, w: float, h: float}
     */
    private function rectToImageStamp(array $rect, float $canvasWidth, float $canvasHeight): array
    {
        return [
            'type' => 'image',
            'x' => $this->toMmX((float) $rect['left'], $canvasWidth),
            'y' => $this->toMmY((float) $rect['top'], $canvasHeight),
            'w' => $this->toMmX((float) $rect['width'], $canvasWidth),
            'h' => $this->toMmY((float) $rect['height'], $canvasHeight),
        ];
    }

    /**
     * @param  array{left: float|int, top: float|int, width: float|int, height: float|int}  $rect
     * @return array{type: string, x: float, y: float}
     */
    private function rectToDateStamp(array $rect, float $canvasWidth, float $canvasHeight): array
    {
        return [
            'type' => 'date',
            'x' => $this->toMmX((float) $rect['left'], $canvasWidth),
            'y' => $this->toMmY((float) $rect['top'] + (float) $rect['height'], $canvasHeight),
        ];
    }

    /**
     * @param  array{type: string, x: float, y: float, w?: float, h?: float}  $stamp
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function imageStampToRect(array $stamp, float $canvasWidth, float $canvasHeight): array
    {
        return [
            'left' => $this->fromMmX((float) $stamp['x'], $canvasWidth),
            'top' => $this->fromMmY((float) $stamp['y'], $canvasHeight),
            'width' => $this->fromMmX((float) ($stamp['w'] ?? 0), $canvasWidth),
            'height' => $this->fromMmY((float) ($stamp['h'] ?? 0), $canvasHeight),
        ];
    }

    /**
     * @param  array{type: string, x: float, y: float}  $stamp
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function dateStampToRect(
        array $stamp,
        float $defaultWidth,
        float $defaultHeight,
        float $canvasWidth,
        float $canvasHeight,
    ): array {
        $dateLeft = $this->fromMmX((float) $stamp['x'], $canvasWidth);
        $dateBottom = $this->fromMmY((float) $stamp['y'], $canvasHeight);

        return [
            'left' => $dateLeft,
            'top' => max(0, $dateBottom - $defaultHeight),
            'width' => $defaultWidth,
            'height' => $defaultHeight,
        ];
    }

    /**
     * @param  array{left: float, top: float, width: float, height: float}  $rect
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function mirrorRect(array $rect, float $canvasWidth): array
    {
        $widthMm = $this->toMmX($rect['width'], $canvasWidth);
        $leftMm = $this->toMmX($rect['left'], $canvasWidth);
        $mirroredLeftMm = self::PAGE_WIDTH_MM - $leftMm - $widthMm;

        return [
            'left' => $this->fromMmX($mirroredLeftMm, $canvasWidth),
            'top' => $rect['top'],
            'width' => $rect['width'],
            'height' => $rect['height'],
        ];
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
}
