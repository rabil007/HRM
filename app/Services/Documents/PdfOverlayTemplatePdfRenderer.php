<?php

namespace App\Services\Documents;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\Exceptions\DocumentTemplateLayoutException;
use App\Support\Documents\Exceptions\DocumentTemplateSourceUnavailableException;
use App\Support\Documents\PdfOverlayLayoutPreflight;
use App\Support\Documents\PdfOverlayPlacementValidator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use setasign\Fpdi\Fpdi;
use Spatie\Browsershot\Browsershot;

class PdfOverlayTemplatePdfRenderer
{
    public function __construct(
        private PdfOverlayLayoutPreflight $preflight = new PdfOverlayLayoutPreflight,
    ) {}

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

        $inspection = $this->preflight->inspectSource($template, $version, $companyId, allowDraft: false);

        if (! $inspection->ok || $inspection->absolutePath === null) {
            $this->logSourceUnavailable($template, $version, $companyId);
            throw new DocumentTemplateSourceUnavailableException;
        }

        $placements = PdfOverlayPlacementValidator::validate(
            $version->placement_config,
            $inspection->pageCount,
        );

        $mergeValues = DocumentTemplateMergeFields::valuesForEmployee($employee);
        $resolvedPlacements = $this->preflight->resolvePlacements(
            $placements,
            $inspection->pageSizes,
            $mergeValues,
        );
        $preflightResult = $this->preflight->measure($resolvedPlacements);

        if (! $preflightResult->valid) {
            $overflow = $preflightResult->firstOverflowException();

            if ($overflow !== null) {
                throw $overflow;
            }

            throw new DocumentTemplateLayoutException(
                fieldKey: '',
                pageNumber: 1,
                message: 'Field value does not fit in the configured placement box even at minimum font size.',
            );
        }

        $effectiveSizes = $preflightResult->effectiveFontSizes;
        $absoluteSourcePath = $inspection->absolutePath;
        $actualPageCount = $inspection->pageCount;
        $pageSizes = $inspection->pageSizes;

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
