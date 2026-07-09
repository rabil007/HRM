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

        // #region agent log
        file_put_contents(
            base_path('.cursor/debug-aa4780.log'),
            json_encode([
                'sessionId' => 'aa4780',
                'location' => 'SalaryDeclarationPdfRenderer.php:render:post-view',
                'message' => 'Salary declaration HTML rendered',
                'data' => [
                    'employeeId' => $employee->id,
                    'companyId' => $companyId,
                    'htmlLength' => strlen($html),
                    'showPlacementGuides' => $showPlacementGuides,
                ],
                'timestamp' => (int) (microtime(true) * 1000),
                'hypothesisId' => 'D',
                'runId' => 'pre-fix',
            ]).PHP_EOL,
            FILE_APPEND,
        );
        // #endregion

        $shot = ConfiguresBrowsershotPdf::apply(
            Browsershot::html($html)
                ->showBackground()
                ->format('A4')
                ->margins(14, 14, 14, 14)
                ->emulateMedia('print'),
        );

        $pdf = $shot->pdf();

        // #region agent log
        file_put_contents(
            base_path('.cursor/debug-aa4780.log'),
            json_encode([
                'sessionId' => 'aa4780',
                'location' => 'SalaryDeclarationPdfRenderer.php:render:post-pdf',
                'message' => 'Browsershot PDF generated',
                'data' => [
                    'employeeId' => $employee->id,
                    'pdfLength' => strlen($pdf),
                    'pdfPrefix' => substr($pdf, 0, 8),
                ],
                'timestamp' => (int) (microtime(true) * 1000),
                'hypothesisId' => 'C',
                'runId' => 'pre-fix',
            ]).PHP_EOL,
            FILE_APPEND,
        );
        // #endregion

        return $pdf;
    }
}
