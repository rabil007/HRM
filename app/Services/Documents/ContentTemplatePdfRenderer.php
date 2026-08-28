<?php

namespace App\Services\Documents;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\Employee;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use App\Support\Documents\DocumentTemplateMergeFields;
use InvalidArgumentException;
use Spatie\Browsershot\Browsershot;

class ContentTemplatePdfRenderer
{
    public function render(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        Employee $employee,
        int $companyId,
    ): string {
        if (! $template->isContent()) {
            throw new InvalidArgumentException("Template format '{$template->template_format->value}' cannot be rendered by ContentTemplatePdfRenderer.");
        }

        $rawContent = (string) ($version->content ?? '');

        // 1. Resolve server-trusted merge field values
        $mergeValues = DocumentTemplateMergeFields::valuesForEmployee($employee);

        // 2. Replace placeholders in content
        $resolvedContent = DocumentTemplateMergeFields::apply($rawContent, $mergeValues);

        // 3. Render HTML with safe escaping in Blade
        $html = view('documents.content-template-pdf', [
            'title' => $template->name,
            'company_name' => $mergeValues['{{company_name}}'] ?? '',
            'date' => $mergeValues['{{today}}'] ?? now()->format('d M Y'),
            'content' => $resolvedContent,
            'embedded_font_styles' => BrowsershotEmbeddedFonts::dejaVuStyles(),
            'show_header' => false,
        ])->render();

        // 4. Generate PDF via Browsershot
        $shot = ConfiguresBrowsershotPdf::apply(
            Browsershot::html($html)
                ->showBackground()
                ->format('A4')
                ->margins(0, 0, 0, 0)
                ->emulateMedia('print'),
        );

        return $shot->pdf();
    }
}
