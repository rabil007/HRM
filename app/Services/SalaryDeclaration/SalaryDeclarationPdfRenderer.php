<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
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
        ConfiguresBrowsershotEnvironment::apply();

        $data = SalaryDeclarationData::for($employee, $companyId, $signature);
        $data['printable'] = false;
        $data['embedded_font_styles'] = BrowsershotEmbeddedFonts::dejaVuStyles();
        $data['show_placement_guides'] = $showPlacementGuides;

        $html = view('employees.salary-declaration', $data)->render();

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
