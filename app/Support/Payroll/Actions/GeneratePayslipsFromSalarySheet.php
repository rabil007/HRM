<?php

namespace App\Support\Payroll\Actions;

use App\Models\Company;
use App\Support\Payroll\PayslipData;
use App\Support\Payroll\Services\SalarySheetPayslipParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class GeneratePayslipsFromSalarySheet
{
    public function __construct(
        private readonly SalarySheetPayslipParser $parser,
    ) {}

    public function handle(
        UploadedFile $file,
        Company $company,
        int $year,
        int $month,
    ): BinaryFileResponse {
        set_time_limit(300);

        $company->loadMissing('currency:id,code,symbol');

        $periodStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $rows = $this->parser->parse($file);

        if ($rows === []) {
            throw new \InvalidArgumentException('No payslip rows with a positive total salary were found in the salary sheet.');
        }

        $tempDir = sys_get_temp_dir().'/salary-sheet-payslips-'.Str::uuid()->toString();
        mkdir($tempDir, 0700, true);

        $zipPath = tempnam(sys_get_temp_dir(), 'payslips_zip_');
        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->cleanupDirectory($tempDir);
            @unlink($zipPath);

            throw new \RuntimeException('Could not create ZIP file.');
        }

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
                $zip->addFile($pdfPath, $baseName);
            }

            $zip->close();
        } catch (\Throwable $exception) {
            $zip->close();
            $this->cleanupDirectory($tempDir);
            @unlink($zipPath);

            throw $exception;
        }

        $this->cleanupDirectory($tempDir);

        $downloadName = sprintf('payslips-%04d-%02d.zip', $year, $month);

        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
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
