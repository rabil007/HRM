<?php

namespace App\Services\SalaryCertificate;

use App\Models\Employee;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use App\Support\Employees\Services\SalaryCertificateData;
use Spatie\Browsershot\Browsershot;

final class SalaryCertificatePdfRenderer implements RendersEmployeeDocumentPdf
{
    public function render(Employee $employee, int $companyId, ?array $signature = null): string
    {
        $data = SalaryCertificateData::for($employee, $companyId);
        $data['printable'] = false;
        $data['is_pdf'] = true;
        $data['embedded_font_styles'] = BrowsershotEmbeddedFonts::dejaVuStyles();

        $html = view('employees.salary-certificate', $data)->render();

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
