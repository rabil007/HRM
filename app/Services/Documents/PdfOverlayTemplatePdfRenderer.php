<?php

namespace App\Services\Documents;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\DocumentTemplateStorage;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;
use App\Support\Documents\Exceptions\DocumentTemplateSourceUnavailableException;
use App\Support\Documents\PdfOverlayPlacementValidator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Spatie\Browsershot\Browsershot;
use Throwable;

class PdfOverlayTemplatePdfRenderer
{
    private const MIN_FONT_SIZE_PT = 8.0;

    private const SHRINK_STEP_PT = 0.25;

    /**
     * @throws InvalidArgumentException
     * @throws DocumentTemplateLayoutException
     * @throws DocumentTemplateSourceUnavailableException
     */
    public function render(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        Employee $employee,
        int $companyId,
    ): string {
        $this->assertRenderable($template, $version, $companyId);

        $storagePath = (string) ($version->source_pdf_path ?? '');
        $storedPageCount = (int) ($version->source_pdf_page_count ?? 0);

        try {
            $absoluteSourcePath = DocumentTemplateStorage::absolutePath($storagePath, $companyId);
        } catch (Throwable) {
            $this->logSourceUnavailable($template, $version, $companyId);
            throw new DocumentTemplateSourceUnavailableException;
        }

        try {
            $inspectPdf = new Fpdi;
            $actualPageCount = $inspectPdf->setSourceFile($absoluteSourcePath);
        } catch (CrossReferenceException|PdfParserException|FpdiException) {
            $this->logSourceUnavailable($template, $version, $companyId);
            throw new DocumentTemplateSourceUnavailableException;
        }

        if ($actualPageCount !== $storedPageCount) {
            $this->logSourceUnavailable($template, $version, $companyId);
            throw new DocumentTemplateSourceUnavailableException;
        }

        $placements = PdfOverlayPlacementValidator::validate(
            $version->placement_config,
            $storedPageCount,
        );

        $mergeValues = DocumentTemplateMergeFields::valuesForEmployee($employee);

        /** @var array<int, array{width: float, height: float, orientation: string}> $pageSizes */
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

        /** @var list<array<string, mixed>> $resolvedPlacements */
        $resolvedPlacements = [];

        foreach ($placements as $placement) {
            $type = $placement['type'] ?? 'field';

            if ($type === 'text') {
                $value = $placement['text_content'] ?? '';
            } else {
                $value = $mergeValues[$placement['field'] ?? ''] ?? '';
            }

            if ($value === '') {
                continue;
            }

            $page = $placement['page'];
            $pageWidth = $pageSizes[$page]['width'];
            $pageHeight = $pageSizes[$page]['height'];
            $verticalAlign = $placement['vertical_align'] ?? PdfOverlayPlacementValidator::normalizeVerticalAlign(null, $type);

            $resolvedPlacements[] = [
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

        $effectiveSizes = $this->preflightFontSizes($resolvedPlacements);

        /** @var array<int, list<array<string, mixed>>> $placementsByPage */
        $placementsByPage = [];

        foreach ($resolvedPlacements as $placement) {
            $placement['effective_font_size'] = $effectiveSizes[$placement['id']];
            $placementsByPage[$placement['page']][] = $placement;
        }

        $fontStyles = BrowsershotEmbeddedFonts::dejaVuStyles();
        $overlayTempPaths = [];

        try {
            foreach ($placementsByPage as $pageNum => $pagePlacements) {
                $html = view('documents.pdf-overlay-page', [
                    'page_width_mm' => $pageSizes[$pageNum]['width'],
                    'page_height_mm' => $pageSizes[$pageNum]['height'],
                    'placements' => $pagePlacements,
                    'embedded_font_styles' => $fontStyles,
                ])->render();

                $overlayPath = tempnam(sys_get_temp_dir(), 'pdf_overlay_');

                if ($overlayPath === false) {
                    throw new \RuntimeException('Failed to allocate temporary overlay file.');
                }

                $pdfOverlayPath = $overlayPath.'.pdf';
                @unlink($overlayPath);

                $overlayTempPaths[$pageNum] = $pdfOverlayPath;

                $shot = ConfiguresBrowsershotPdf::apply(
                    Browsershot::html($html)
                        ->paperSize($pageSizes[$pageNum]['width'], $pageSizes[$pageNum]['height'], 'mm')
                        ->margins(0, 0, 0, 0, 'mm')
                        ->transparentBackground()
                        ->emulateMedia('print'),
                );

                $shot->save($pdfOverlayPath);
            }

            return $this->composeFinalPdf(
                absoluteSourcePath: $absoluteSourcePath,
                pageSizes: $pageSizes,
                overlayTempPaths: $overlayTempPaths,
                actualPageCount: $actualPageCount,
            );
        } finally {
            foreach ($overlayTempPaths as $tempPath) {
                if (is_string($tempPath) && file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }
    }

    private function assertRenderable(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
    ): void {
        if (! $template->isPdfOverlay()) {
            throw new InvalidArgumentException(
                "Template format '{$template->template_format->value}' cannot be rendered by PdfOverlayTemplatePdfRenderer."
            );
        }

        if ($template->company_id !== $companyId) {
            throw new InvalidArgumentException('Template does not belong to the expected company.');
        }

        if ($version->company_id !== $companyId) {
            throw new InvalidArgumentException('Template version does not belong to the expected company.');
        }

        if ((int) $version->document_generation_template_id !== (int) $template->id) {
            throw new InvalidArgumentException('Template version does not belong to the expected template.');
        }

        if ($version->isDraft()) {
            throw new InvalidArgumentException('Draft versions cannot be used for generation.');
        }

        if ((int) ($version->source_pdf_page_count ?? 0) < 1) {
            $this->logSourceUnavailable($template, $version, $companyId);
            throw new DocumentTemplateSourceUnavailableException;
        }

        if ((string) ($version->source_pdf_path ?? '') === '') {
            $this->logSourceUnavailable($template, $version, $companyId);
            throw new DocumentTemplateSourceUnavailableException;
        }
    }

    /**
     * Measure every non-empty placement in a single Chromium pass and return
     * the largest font size (requested → 8pt in 0.25pt steps) that fits.
     *
     * @param  list<array<string, mixed>>  $placements
     * @return array<string, float>
     *
     * @throws DocumentTemplateLayoutException
     */
    private function preflightFontSizes(array $placements): array
    {
        if ($placements === []) {
            return [];
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

        try {
            $raw = ConfiguresBrowsershotPdf::apply(
                Browsershot::html($html),
            )->evaluate($pageFunction);
        } catch (Throwable $e) {
            throw new \RuntimeException('PDF overlay layout measurement failed.', 0, $e);
        }

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

        foreach ($placements as $index => $placement) {
            if ($chosen[$index] === null) {
                throw new DocumentTemplateLayoutException(
                    fieldKey: ($placement['is_static_text'] ?? false)
                        ? ''
                        : (string) ($placement['field'] ?? ''),
                    pageNumber: (int) $placement['page'],
                    message: 'Field value does not fit in the configured placement box even at minimum font size.',
                    placementId: (string) $placement['id'],
                );
            }

            $effective[(string) $placement['id']] = $chosen[$index];
        }

        return $effective;
    }

    /**
     * @return list<float>
     */
    private function fontSizeCandidates(float $requestedPt): array
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
     * @param  array<int, array{width: float, height: float, orientation: string}>  $pageSizes
     * @param  array<int, string>  $overlayTempPaths
     */
    private function composeFinalPdf(
        string $absoluteSourcePath,
        array $pageSizes,
        array $overlayTempPaths,
        int $actualPageCount,
    ): string {
        $pdf = new Fpdi;
        $pdf->setSourceFile($absoluteSourcePath);

        for ($pageNum = 1; $pageNum <= $actualPageCount; $pageNum++) {
            $sourceTplId = $pdf->importPage($pageNum);
            $size = $pageSizes[$pageNum];

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($sourceTplId, 0, 0, $size['width'], $size['height']);

            if (isset($overlayTempPaths[$pageNum])) {
                $pdf->setSourceFile($overlayTempPaths[$pageNum]);
                $overlayTplId = $pdf->importPage(1);
                $pdf->useTemplate($overlayTplId, 0, 0, $size['width'], $size['height']);
                $pdf->setSourceFile($absoluteSourcePath);
            }
        }

        return $pdf->Output('S');
    }

    private function logSourceUnavailable(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
    ): void {
        Log::warning('PDF overlay source unavailable', [
            'company_id' => $companyId,
            'template_id' => $template->id,
            'template_version_id' => $version->id,
            'error_code' => 'TEMPLATE_SOURCE_UNAVAILABLE',
        ]);
    }
}
