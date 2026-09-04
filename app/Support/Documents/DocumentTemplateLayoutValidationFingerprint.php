<?php

namespace App\Support\Documents;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use JsonException;
use Throwable;

final class DocumentTemplateLayoutValidationFingerprint
{
    /**
     * Bump when authoritative layout-fit semantics change so existing valid
     * runs cannot authorize publish under a previous measurement definition.
     */
    public const ENGINE_VERSION = 1;

    /**
     * @param  array<string, mixed>|null  $placementConfig
     */
    public function for(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        ?array $placementConfig,
        string $mode,
        ?int $employeeId,
        bool $authoritative,
    ): string {
        return $this->hash($this->payload(
            $template,
            $version,
            $companyId,
            $placementConfig,
            $mode,
            $employeeId,
            $authoritative,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $placementConfig
     * @return array<string, mixed>
     */
    public function payload(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        ?array $placementConfig,
        string $mode,
        ?int $employeeId,
        bool $authoritative,
    ): array {
        $template->loadMissing('company');

        $payload = [
            'engine_version' => self::ENGINE_VERSION,
            'template_id' => (int) $template->id,
            'version_id' => (int) $version->id,
            'source_pdf_sha256' => $this->sourcePdfContentSha256($version, $companyId),
            'source_pdf_page_count' => (int) ($version->source_pdf_page_count ?? 0),
            'placement_config' => $this->canonicalPlacementConfig($placementConfig),
            'mode' => $mode,
            'authoritative' => $authoritative,
            'employee_id' => $mode === 'employee' ? $employeeId : null,
        ];

        if ($mode === 'sample') {
            $payload['sample_values'] = DocumentTemplateMergeFields::sampleValues($template->company?->name);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    public function sourcePdfContentSha256(DocumentGenerationTemplateVersion $version, int $companyId): string
    {
        $storagePath = (string) ($version->source_pdf_path ?? '');

        if ($storagePath === '') {
            return 'unavailable';
        }

        try {
            $absolutePath = DocumentTemplateStorage::absolutePath($storagePath, $companyId);
            $hash = hash_file('sha256', $absolutePath);

            return is_string($hash) && $hash !== '' ? $hash : 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * @param  array<string, mixed>|null  $placementConfig
     * @return array<string, mixed>
     */
    public function canonicalPlacementConfig(?array $placementConfig): array
    {
        $placements = is_array($placementConfig['placements'] ?? null)
            ? $placementConfig['placements']
            : [];

        $normalized = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                continue;
            }

            $type = (string) ($placement['type'] ?? 'field');
            $fontColor = PdfOverlayPlacementValidator::normalizeFontColor(
                $placement['font_color'] ?? PdfOverlayPlacementValidator::DEFAULT_FONT_COLOR,
            );

            $normalized[] = [
                'id' => (string) ($placement['id'] ?? ''),
                'type' => $type,
                'field' => $placement['field'] ?? null,
                'text_content' => $placement['text_content'] ?? null,
                'page' => (int) ($placement['page'] ?? 1),
                'x' => round((float) ($placement['x'] ?? 0), 6),
                'y' => round((float) ($placement['y'] ?? 0), 6),
                'width' => round((float) ($placement['width'] ?? 0), 6),
                'height' => round((float) ($placement['height'] ?? 0), 6),
                'font_size' => (int) ($placement['font_size'] ?? 12),
                'font_weight' => (string) ($placement['font_weight'] ?? 'normal'),
                'text_align' => (string) ($placement['text_align'] ?? 'left'),
                'font_family' => PdfOverlayPlacementValidator::normalizeFontFamily($placement['font_family'] ?? 'sans'),
                'font_color' => $fontColor ?? '#000000',
                'vertical_align' => PdfOverlayPlacementValidator::normalizeVerticalAlign(
                    $placement['vertical_align'] ?? null,
                    $type,
                ),
            ];
        }

        usort($normalized, fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));

        return [
            'schema_version' => 2,
            'placements' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $this->ksortRecursive($payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to canonicalize layout validation fingerprint.', 0, $exception);
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->ksortRecursive($item);
            }
        }

        if (array_is_list($value)) {
            return $value;
        }

        ksort($value);

        return $value;
    }
}
