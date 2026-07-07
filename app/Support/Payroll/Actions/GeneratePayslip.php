<?php

namespace App\Support\Payroll\Actions;

use App\Models\PayrollRecord;
use App\Support\Payroll\PayslipData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GeneratePayslip
{
    public function handle(PayrollRecord $record): PayrollRecord
    {
        $record->loadMissing('employee');

        $dataStartedAt = microtime(true);
        $data = PayslipData::for($record, (int) $record->company_id);
        $dataDurationMs = (int) round((microtime(true) - $dataStartedAt) * 1000);
        $data['printable'] = false;
        $data['is_pdf'] = true;

        $employeeNo = Str::slug((string) ($record->employee?->employee_no ?: 'employee'));
        $relativePath = sprintf(
            'payslips/%d/%d/%s.pdf',
            $record->company_id,
            $record->period_id,
            $employeeNo,
        );

        $pdfStartedAt = microtime(true);
        $pdf = Pdf::loadView('payroll.payslip', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdfDurationMs = (int) round((microtime(true) - $pdfStartedAt) * 1000);

        $storageStartedAt = microtime(true);
        Storage::disk('local')->put($relativePath, $pdf->output());
        $storageDurationMs = (int) round((microtime(true) - $storageStartedAt) * 1000);

        $record->forceFill(['payslip_path' => $relativePath])->save();

        // #region agent log
        file_put_contents(
            '/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-386635.log',
            json_encode([
                'sessionId' => '386635',
                'hypothesisId' => 'A',
                'location' => 'GeneratePayslip.php:handle',
                'message' => 'payslip_timing_breakdown',
                'data' => [
                    'record_id' => $record->id,
                    'employee_id' => $record->employee_id,
                    'data_ms' => $dataDurationMs,
                    'pdf_ms' => $pdfDurationMs,
                    'storage_ms' => $storageDurationMs,
                    'total_ms' => $dataDurationMs + $pdfDurationMs + $storageDurationMs,
                    'has_company_logo' => filled($data['company_logo'] ?? null),
                ],
                'timestamp' => (int) (microtime(true) * 1000),
            ])."\n",
            FILE_APPEND,
        );
        // #endregion

        return $record->fresh();
    }
}
