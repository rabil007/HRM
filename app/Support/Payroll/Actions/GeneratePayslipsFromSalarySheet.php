<?php

namespace App\Support\Payroll\Actions;

use App\Models\Company;
use App\Services\DocumentMergeService;
use App\Support\Payroll\PayslipData;
use App\Support\Payroll\Services\SalarySheetPayslipParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GeneratePayslipsFromSalarySheet
{
    public function __construct(
        private readonly SalarySheetPayslipParser $parser,
        private readonly DocumentMergeService $documentMergeService,
    ) {}

    /**
     * @param  list<int>  $rowNumbers
     */
    public function handle(
        UploadedFile $file,
        Company $company,
        int $year,
        int $month,
        array $rowNumbers,
    ): StreamedResponse {
        set_time_limit(300);

        $company->loadMissing('currency:id,code,symbol');

        $periodStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $selectedRows = array_fill_keys(array_map('intval', $rowNumbers), true);
        $rows = array_values(array_filter(
            $this->parser->parse($file),
            fn (array $row): bool => isset($selectedRows[(int) $row['row']]),
        ));

        if ($rows === []) {
            throw new \InvalidArgumentException('No selected payslip rows with a positive total salary were found.');
        }

        $tempDir = sys_get_temp_dir().'/salary-sheet-payslips-'.Str::uuid()->toString();
        mkdir($tempDir, 0700, true);

        $paths = [];
        $usedNames = [];

        try {
            foreach ($rows as $row) {
                $data = PayslipData::fromSalarySheetRow($row, $company, $periodStart, $periodEnd);

                $pdf = Pdf::loadView('payroll.payslip', $data)
                    ->setPaper('a4', 'portrait')
                    ->setOption('isRemoteEnabled', true)
                    ->setOption('defaultFont', 'DejaVu Sans');

                $baseName = $this->uniqueFilename((string) $row['employee_no'], $usedNames);
                $pdfPath = $tempDir.'/'.$baseName;
                file_put_contents($pdfPath, $pdf->output());
                $paths[] = $pdfPath;
            }

            $mergedPath = count($paths) === 1
                ? $this->copyToTempPdf($paths[0])
                : $this->documentMergeService->buildMergedPdfFromPaths($paths);
        } catch (\Throwable $exception) {
            $this->cleanupDirectory($tempDir);

            throw $exception;
        }

        $this->cleanupDirectory($tempDir);

        $downloadName = sprintf('payslips-%04d-%02d.pdf', $year, $month);

        return response()->streamDownload(function () use ($mergedPath): void {
            $handle = fopen($mergedPath, 'rb');

            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }

            @unlink($mergedPath);
        }, $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function copyToTempPdf(string $sourcePath): string
    {
        $mergedPath = tempnam(sys_get_temp_dir(), 'payslips_merged_');

        if ($mergedPath === false) {
            throw new \RuntimeException('Could not create temporary PDF file.');
        }

        $targetPath = $mergedPath.'.pdf';
        @unlink($mergedPath);

        if (! copy($sourcePath, $targetPath)) {
            throw new \RuntimeException('Could not prepare payslip PDF for download.');
        }

        return $targetPath;
    }

    /**
     * @param  array<string, true>  $usedNames
     */
    private function uniqueFilename(string $employeeNo, array &$usedNames): string
    {
        $slug = Str::slug($employeeNo !== '' ? $employeeNo : 'employee') ?: 'employee';
        $filename = $slug.'.pdf';
        $counter = 1;

        while (isset($usedNames[$filename])) {
            $counter++;
            $filename = $slug.'-'.$counter.'.pdf';
        }

        $usedNames[$filename] = true;

        return $filename;
    }

    private function cleanupDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
