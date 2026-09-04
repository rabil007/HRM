<?php

namespace App\Support\Documents;

use App\Enums\DocumentTemplateLayoutPreflightStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\DocumentGenerationItemErrorPresenter;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Throwable;

final class PdfOverlayLayoutPreflight
{
    public const MIN_FONT_SIZE_PT = 8.0;

    public const SHRINK_STEP_PT = 0.25;

    public const ISSUE_LAYOUT_OVERFLOW = 'LAYOUT_OVERFLOW';

    public const CODE_SOURCE_UNAVAILABLE = 'TEMPLATE_SOURCE_UNAVAILABLE';

    public const CODE_LAYOUT_CONFIGURATION_INVALID = 'TEMPLATE_LAYOUT_CONFIGURATION_INVALID';

    public const CODE_LAYOUT_VALIDATION_UNAVAILABLE = 'TEMPLATE_LAYOUT_VALIDATION_UNAVAILABLE';

    /**
     * @deprecated Use CODE_LAYOUT_VALIDATION_UNAVAILABLE for engine failures and CODE_LAYOUT_CONFIGURATION_INVALID for placement config.
     */
    public const CODE_LAYOUT_VALIDATION_FAILED = 'TEMPLATE_LAYOUT_VALIDATION_FAILED';

    public function __construct(
        private PdfOverlayLayoutMeasurementClient $measurementClient = new PdfOverlayLayoutMeasurementClient,
        private DocumentTemplateLayoutValidationFailureLogger $failureLogger = new DocumentTemplateLayoutValidationFailureLogger,
    ) {}

    public function inspectSource(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        bool $allowDraft = false,
    ): PdfOverlaySourceInspection {
        $issue = $this->sourceUnavailableIssue();

        if (! $template->isPdfOverlay()) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        if ($template->company_id !== $companyId || $version->company_id !== $companyId) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        if ((int) $version->document_generation_template_id !== (int) $template->id) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        if ($version->isDraft() && ! $allowDraft) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        $storagePath = (string) ($version->source_pdf_path ?? '');
        $storedPageCount = (int) ($version->source_pdf_page_count ?? 0);

        if ($storagePath === '' || $storedPageCount < 1) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        try {
            $absoluteSourcePath = DocumentTemplateStorage::absolutePath($storagePath, $companyId);
        } catch (Throwable) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        try {
            $inspectPdf = new Fpdi;
            $actualPageCount = $inspectPdf->setSourceFile($absoluteSourcePath);
        } catch (CrossReferenceException|PdfParserException|FpdiException|Throwable) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        if ($actualPageCount !== $storedPageCount) {
            return PdfOverlaySourceInspection::failed($issue);
        }

        $pageSizes = [];

        for ($pageNum = 1; $pageNum <= $actualPageCount; $pageNum++) {
            $tplId = $inspectPdf->importPage($pageNum);
            $size = $inspectPdf->getTemplateSize($tplId);
            $pageSizes[$pageNum] = [
                'width' => (float) $size['width'],
                'height' => (float) $size['height'],
                'orientation' => (string) ($size['orientation'] ?? 'P'),
            ];
        }

        return new PdfOverlaySourceInspection(
            ok: true,
            absolutePath: $absoluteSourcePath,
            pageCount: $actualPageCount,
            pageSizes: $pageSizes,
            issue: null,
        );
    }

    /**
     * @param  array<string, string>  $mergeValues
     * @param  array<string, mixed>|null  $placementConfig
     * @param  array{mode?: string, user_id?: int|null}|null  $context
     */
    public function evaluate(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        array $mergeValues,
        ?array $placementConfig = null,
        bool $allowDraft = false,
        ?array $context = null,
    ): DocumentTemplateLayoutPreflightResult {
        $inspection = $this->inspectSource($template, $version, $companyId, $allowDraft);

        if (! $inspection->ok) {
            return DocumentTemplateLayoutPreflightResult::invalid([
                $inspection->issue ?? $this->sourceUnavailableIssue(),
            ]);
        }

        $config = $placementConfig ?? $version->placement_config;

        try {
            $placements = PdfOverlayPlacementValidator::validate(
                is_array($config) ? $config : null,
                $inspection->pageCount,
            );
        } catch (InvalidArgumentException) {
            return DocumentTemplateLayoutPreflightResult::invalid([$this->configurationInvalidIssue()]);
        }

        $resolved = $this->resolvePlacements($placements, $inspection->pageSizes, $mergeValues);

        try {
            return $this->measure($resolved);
        } catch (Throwable $e) {
            $reference = DocumentTemplateLayoutValidationFailureLogger::newReference();
            $this->failureLogger->record($e, $reference, [
                'company_id' => $companyId,
                'template_id' => (int) $template->id,
                'template_version_id' => (int) $version->id,
                'template_type' => $template->template_format->value,
                'validation_mode' => $context['mode'] ?? null,
                'user_id' => $context['user_id'] ?? null,
            ]);

            return DocumentTemplateLayoutPreflightResult::unavailable(
                [$this->validationUnavailableIssue($reference)],
                $this->emptyEffectiveSizes($resolved),
                $reference,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @param  array<int, array{width: float, height: float, orientation: string}>  $pageSizes
     * @param  array<string, string>  $mergeValues
     * @return list<array<string, mixed>>
     */
    public function resolvePlacements(array $placements, array $pageSizes, array $mergeValues): array
    {
        $resolved = [];

        foreach ($placements as $placement) {
            $type = $placement['type'] ?? 'field';

            if ($type === 'text') {
                $value = (string) ($placement['text_content'] ?? '');
            } else {
                $value = (string) ($mergeValues[$placement['field'] ?? ''] ?? '');
            }

            if ($value === '') {
                continue;
            }

            $page = (int) $placement['page'];
            $pageWidth = $pageSizes[$page]['width'];
            $pageHeight = $pageSizes[$page]['height'];
            $verticalAlign = $placement['vertical_align'] ?? PdfOverlayPlacementValidator::normalizeVerticalAlign(null, $type);

            $resolved[] = [
                'id' => $placement['id'],
                'type' => $type,
                'field' => $placement['field'] ?? null,
                'value' => $value,
                'page' => $page,
                'left_mm' => $placement['x'] * $pageWidth,
                'top_mm' => $placement['y'] * $pageHeight,
                'width_mm' => $placement['width'] * $pageWidth,
                'height_mm' => $placement['height'] * $pageHeight,
                'requested_font_size' => (float) $placement['font_size'],
                'font_weight' => $placement['font_weight'],
                'text_align' => $placement['text_align'],
                'vertical_align' => $verticalAlign,
                'vertical_align_css' => PdfOverlayPlacementValidator::cssVerticalAlign($verticalAlign),
                'font_family' => $placement['font_family'] ?? 'sans',
                'font_family_css' => PdfOverlayPlacementValidator::cssFontFamily(
                    (string) ($placement['font_family'] ?? 'sans'),
                ),
                'font_color' => $placement['font_color'] ?? PdfOverlayPlacementValidator::DEFAULT_FONT_COLOR,
                'is_static_text' => $type === 'text',
            ];
        }

        return $resolved;
    }

    /**
     * Measure every non-empty placement in a single Chromium pass.
     *
     * @param  list<array<string, mixed>>  $placements
     *
     * @throws \RuntimeException
     */
    public function measure(array $placements): DocumentTemplateLayoutPreflightResult
    {
        if ($placements === []) {
            return DocumentTemplateLayoutPreflightResult::valid();
        }

        $fontStyles = BrowsershotEmbeddedFonts::dejaVuStyles();
        $boxesHtml = '';
        $measureJs = '';

        foreach ($placements as $index => $placement) {
            $candidates = $this->fontSizeCandidates((float) $placement['requested_font_size']);
            $escapedValue = htmlspecialchars((string) $placement['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fontWeightCss = $placement['font_weight'] === 'bold' ? 'bold' : 'normal';
            $fontFamilyCss = htmlspecialchars(
                PdfOverlayPlacementValidator::cssFontFamily((string) ($placement['font_family'] ?? 'sans')),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            $widthMm = (float) $placement['width_mm'];
            $heightMm = (float) $placement['height_mm'];

            foreach ($candidates as $candidateIndex => $candidatePt) {
                $boxId = "b{$index}_{$candidateIndex}";
                $boxesHtml .= "<div id=\"{$boxId}\" style=\"width:{$widthMm}mm;height:{$heightMm}mm;font-size:{$candidatePt}pt;font-weight:{$fontWeightCss};font-family:{$fontFamilyCss};white-space:pre-wrap;overflow-wrap:break-word;word-break:normal;line-height:1.2;overflow:hidden;display:flex;align-items:flex-start;box-sizing:border-box;\" dir=\"auto\"><span style=\"unicode-bidi:plaintext;display:block;width:100%;\">{$escapedValue}</span></div>\n";
                $measureJs .= "var el{$index}_{$candidateIndex}=document.getElementById('{$boxId}'); results.push({id:{$index},size:{$candidatePt},overflow:el{$index}_{$candidateIndex}.scrollWidth>el{$index}_{$candidateIndex}.clientWidth+1||el{$index}_{$candidateIndex}.scrollHeight>el{$index}_{$candidateIndex}.clientHeight+1});\n";
            }
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
{$fontStyles}
body { margin:0; padding:0; font-family:'DejaVu Sans',sans-serif; }
</style>
</head>
<body>
{$boxesHtml}
</body>
</html>
HTML;

        $pageFunction = 'document.fonts.ready.then(function(){ var results = []; '.$measureJs.' return JSON.stringify(results); })';

        $raw = $this->measurementClient->evaluateHtml($html, $pageFunction);

        $measures = json_decode($raw, true);

        if (is_string($measures)) {
            $measures = json_decode($measures, true);
        }

        if (! is_array($measures) || $measures === []) {
            throw new \RuntimeException('PDF overlay layout measurement failed.');
        }

        /** @var array<int, float|null> $chosen */
        $chosen = [];

        foreach ($placements as $index => $placement) {
            $chosen[$index] = null;
        }

        foreach ($measures as $measure) {
            if (! is_array($measure) || ! isset($measure['id'], $measure['size'], $measure['overflow'])) {
                continue;
            }

            $index = (int) $measure['id'];

            if (! array_key_exists($index, $chosen) || $chosen[$index] !== null) {
                continue;
            }

            if (! (bool) $measure['overflow']) {
                $chosen[$index] = (float) $measure['size'];
            }
        }

        $effective = [];
        $issues = [];

        foreach ($placements as $index => $placement) {
            $id = (string) $placement['id'];

            if ($chosen[$index] === null) {
                $effective[$id] = null;
                $issues[] = $this->overflowIssue($placement);

                continue;
            }

            $effective[$id] = $chosen[$index];
        }

        if ($issues === []) {
            return new DocumentTemplateLayoutPreflightResult(
                status: DocumentTemplateLayoutPreflightStatus::Valid,
                valid: true,
                effectiveFontSizes: $effective,
                issues: [],
            );
        }

        return DocumentTemplateLayoutPreflightResult::invalid($issues, $effective);
    }

    /**
     * @return list<float>
     */
    public function fontSizeCandidates(float $requestedPt): array
    {
        $candidates = [];
        $sizeHundredths = (int) round($requestedPt * 100);
        $minHundredths = (int) (self::MIN_FONT_SIZE_PT * 100);
        $stepHundredths = (int) (self::SHRINK_STEP_PT * 100);

        if ($sizeHundredths < $minHundredths) {
            $sizeHundredths = $minHundredths;
        }

        for ($size = $sizeHundredths; $size >= $minHundredths; $size -= $stepHundredths) {
            $candidates[] = round($size / 100, 2);
        }

        if ($candidates === [] || end($candidates) !== self::MIN_FONT_SIZE_PT) {
            $candidates[] = self::MIN_FONT_SIZE_PT;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array{
     *     code: string,
     *     severity: string,
     *     placement_id: string,
     *     field_key: string|null,
     *     field_label: string|null,
     *     page: int,
     *     message: string,
     *     test_value: string
     * }
     */
    private function overflowIssue(array $placement): array
    {
        $isStatic = (bool) ($placement['is_static_text'] ?? false);
        $fieldKey = $isStatic ? '' : (string) ($placement['field'] ?? '');
        $page = (int) $placement['page'];
        $label = $isStatic ? 'Text box' : DocumentTemplateMergeFields::labelFor($fieldKey);

        return [
            'code' => self::ISSUE_LAYOUT_OVERFLOW,
            'severity' => 'error',
            'placement_id' => (string) $placement['id'],
            'field_key' => $isStatic ? null : $fieldKey,
            'field_label' => $label,
            'page' => $page,
            'message' => DocumentGenerationItemErrorPresenter::layoutOverflowMessageForField($fieldKey, $page),
            'test_value' => (string) $placement['value'],
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     severity: string,
     *     placement_id: null,
     *     field_key: null,
     *     field_label: null,
     *     page: null,
     *     message: string
     * }
     */
    private function sourceUnavailableIssue(): array
    {
        return [
            'code' => self::CODE_SOURCE_UNAVAILABLE,
            'severity' => 'error',
            'placement_id' => null,
            'field_key' => null,
            'field_label' => null,
            'page' => null,
            'message' => 'The template source PDF is unavailable or invalid.',
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     severity: string,
     *     placement_id: null,
     *     field_key: null,
     *     field_label: null,
     *     page: null,
     *     message: string
     * }
     */
    private function configurationInvalidIssue(): array
    {
        return [
            'code' => self::CODE_LAYOUT_CONFIGURATION_INVALID,
            'severity' => 'error',
            'placement_id' => null,
            'field_key' => null,
            'field_label' => null,
            'page' => null,
            'message' => 'The template placement configuration is invalid.',
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     severity: string,
     *     placement_id: null,
     *     field_key: null,
     *     field_label: null,
     *     page: null,
     *     message: string,
     *     reference: string
     * }
     */
    private function validationUnavailableIssue(string $reference): array
    {
        return [
            'code' => self::CODE_LAYOUT_VALIDATION_UNAVAILABLE,
            'severity' => 'error',
            'placement_id' => null,
            'field_key' => null,
            'field_label' => null,
            'page' => null,
            'message' => 'The PDF validation engine could not complete the layout check.',
            'reference' => $reference,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @return array<string, null>
     */
    private function emptyEffectiveSizes(array $placements): array
    {
        $sizes = [];

        foreach ($placements as $placement) {
            $sizes[(string) $placement['id']] = null;
        }

        return $sizes;
    }
}
