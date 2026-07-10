<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use App\Support\Employees\Services\SalaryDeclarationData;
use Spatie\Browsershot\Browsershot;

final class SalaryDeclarationPdfRenderer implements RendersEmployeeDocumentPdf, RendersSalaryDeclarationPdf
{
    public function render(
        Employee $employee,
        int $companyId,
        ?array $signature = null,
        bool $showPlacementGuides = false,
    ): string {
        $data = SalaryDeclarationData::for($employee, $companyId, $signature);
        $data['printable'] = false;
        $data['embedded_font_styles'] = BrowsershotEmbeddedFonts::dejaVuStyles();
        $data['show_placement_guides'] = $showPlacementGuides;

        $html = view('employees.salary-declaration', $data)->render();

        $shot = ConfiguresBrowsershotPdf::apply(
            Browsershot::html($html)
                ->showBackground()
                ->format('A4')
                ->margins(14, 14, 14, 14)
                ->emulateMedia('print'),
        );

        return $shot->pdf();
    }
}
