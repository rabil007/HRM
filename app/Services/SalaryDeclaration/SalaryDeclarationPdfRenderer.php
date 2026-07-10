<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Support\BulkDocuments\BrowsershotEmbeddedFonts;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
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
        $data = SalaryDeclarationData::for($employee, $companyId, $signature);
        $data['printable'] = false;
        $data['embedded_font_styles'] = BrowsershotEmbeddedFonts::dejaVuStyles();
        $data['show_placement_guides'] = $showPlacementGuides;

        $html = view('employees.salary-declaration', $data)->render();

        // #region agent log
        try {
            $binaries = ResolvesBrowsershotBinaries::resolve();
            $binaryLog = ['node' => $binaries['node'], 'npm' => $binaries['npm'], 'chrome' => $binaries['chrome'], 'chrome_executable' => $binaries['chrome'] !== null ? is_executable($binaries['chrome']) : false];
        } catch (\Throwable $binaryException) {
            $binaryLog = ['resolve_error' => $binaryException::class, 'resolve_message' => $binaryException->getMessage()];
        }
        @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'pre-fix', 'hypothesisId' => 'A,E', 'location' => 'SalaryDeclarationPdfRenderer.php:render:before-browsershot', 'message' => 'browsershot binaries resolved', 'data' => ['binaries' => $binaryLog, 'html_length' => strlen($html), 'has_signature_image' => ! empty($signature['signature_image_url'] ?? null), 'signature_image_length' => strlen((string) ($signature['signature_image_url'] ?? ''))], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        $shot = ConfiguresBrowsershotPdf::apply(
            Browsershot::html($html)
                ->showBackground()
                ->format('A4')
                ->margins(14, 14, 14, 14)
                ->emulateMedia('print'),
        );

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'pre-fix', 'hypothesisId' => 'B', 'location' => 'SalaryDeclarationPdfRenderer.php:render:before-pdf', 'message' => 'calling browsershot pdf()', 'data' => [], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        $pdf = $shot->pdf();

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'pre-fix', 'hypothesisId' => 'B', 'location' => 'SalaryDeclarationPdfRenderer.php:render:after-pdf', 'message' => 'browsershot pdf generated', 'data' => ['pdf_prefix' => substr($pdf, 0, 8), 'pdf_length' => strlen($pdf)], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        return $pdf;
    }
}
