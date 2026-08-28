<?php

namespace App\Services\Documents;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;

class CustomTemplatePdfRenderer
{
    public function __construct(
        private ContentTemplatePdfRenderer $contentRenderer,
        private PdfOverlayTemplatePdfRenderer $overlayRenderer,
    ) {}

    /**
     * Render a custom template to PDF bytes for the given employee.
     *
     * @return string raw PDF bytes
     */
    public function render(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        Employee $employee,
        int $companyId,
    ): string {
        return match ($template->template_format) {
            DocumentGenerationTemplateFormat::Content => $this->contentRenderer->render($template, $version, $employee, $companyId),
            DocumentGenerationTemplateFormat::PdfOverlay => $this->overlayRenderer->render($template, $version, $employee, $companyId),
        };
    }
}
