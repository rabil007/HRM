<?php

namespace App\Support\Payroll\Actions;

use App\Models\PayrollRecord;
use App\Support\Payroll\PayslipData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class GeneratePayslip
{
    /**
     * @param  list<array<string, mixed>>|null  $preloadedSalaryInputLines
     */
    public function handle(PayrollRecord $record, ?array $preloadedSalaryInputLines = null): PayrollRecord
    {
        $record->loadMissing('employee');

        $data = PayslipData::for($record, (int) $record->company_id, $preloadedSalaryInputLines);
        $data['printable'] = false;
        $data['is_pdf'] = true;

        $employeeNo = Str::slug((string) ($record->employee?->employee_no ?: 'employee'));
        $relativePath = sprintf(
            'payslips/%d/%d/%s.pdf',
            $record->company_id,
            $record->period_id,
            $employeeNo,
        );

        $pdf = Pdf::loadView('payroll.payslip', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');

        Storage::disk('local')->put($relativePath, $pdf->output());

        $record->forceFill(['payslip_path' => $relativePath])->save();

        return $record;
    }
}
