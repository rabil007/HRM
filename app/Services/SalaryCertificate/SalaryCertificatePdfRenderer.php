<?php

namespace App\Services\SalaryCertificate;

use App\Models\Employee;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use App\Support\Employees\Services\SalaryCertificateData;
use Spatie\Browsershot\Browsershot;

final class SalaryCertificatePdfRenderer implements RendersEmployeeDocumentPdf
{
    public function render(Employee $employee, int $companyId, ?array $signature = null): string
    {
        ConfiguresBrowsershotEnvironment::apply();

        $data = SalaryCertificateData::for($employee, $companyId);
        $data['printable'] = false;
        $data['is_pdf'] = true;
        $data['embedded_font_styles'] = BrowsershotEmbeddedFonts::dejaVuStyles();

        $html = view('employees.salary-certificate', $data)->render();

        $binaries = ResolvesBrowsershotBinaries::resolve();

        $shot = Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(14, 14, 14, 14)
            ->emulateMedia('print')
            ->setNodeModulePath(base_path('node_modules'))
            ->setNodeBinary($binaries['node'])
            ->setNpmBinary($binaries['npm'])
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage',
                'disable-gpu',
            ]);

        if ($binaries['chrome'] !== null) {
            $shot->setChromePath($binaries['chrome']);
        }

        return $shot->pdf();
    }
}
