<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Support\Payroll\PayslipData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayslipController extends Controller
{
    public function show(Request $request, PayrollRecord $payrollRecord): BinaryFileResponse|Response|StreamedResponse|View|\Symfony\Component\HttpFoundation\Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollRecord->company_id === $companyId, 404);
        $this->authorizeAccess($request);

        if ($request->header('X-Inertia') && $request->query('view') !== 'html') {
            return Inertia::location(route('payroll.payslips.show', $payrollRecord));
        }

        $data = PayslipData::for($payrollRecord, $companyId);

        if ($request->query('format') === 'pdf' && ! $request->boolean('inline')) {
            $data['printable'] = false;
            $data['is_pdf'] = true;

            return $this->streamPdf($payrollRecord, $data);
        }

        if ($request->query('view') === 'html') {
            $data['printable'] = true;
            $data['is_pdf'] = true;
            $data['download_url'] = route('payroll.payslips.download', $payrollRecord);

            return view('payroll.payslip', $data);
        }

        return $this->inlinePdf($payrollRecord, $data);
    }

    public function download(Request $request, PayrollRecord $payrollRecord): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollRecord->company_id === $companyId, 404);
        $this->authorizeAccess($request);

        if (filled($payrollRecord->payslip_path) && Storage::disk('local')->exists($payrollRecord->payslip_path)) {
            $filename = basename($payrollRecord->payslip_path);

            return Storage::disk('local')->download($payrollRecord->payslip_path, $filename);
        }

        $data = PayslipData::for($payrollRecord, $companyId);
        $data['printable'] = false;
        $data['is_pdf'] = true;

        return $this->streamPdf($payrollRecord, $data);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(
            $request->user()?->can('payroll.records.view') || $request->user()?->can('payroll.periods.view'),
            403,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function inlinePdf(PayrollRecord $record, array $data): BinaryFileResponse|Response
    {
        $record->loadMissing('employee');
        $filename = $this->payslipFilename($record);

        if (filled($record->payslip_path) && Storage::disk('local')->exists($record->payslip_path)) {
            return response()->file(
                Storage::disk('local')->path($record->payslip_path),
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.$filename.'"',
                ],
            );
        }

        $pdf = Pdf::loadView('payroll.payslip', array_merge($data, [
            'printable' => false,
            'is_pdf' => true,
        ]))
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function streamPdf(PayrollRecord $record, array $data): StreamedResponse
    {
        $record->loadMissing('employee');
        $filename = $this->payslipFilename($record);

        $pdf = Pdf::loadView('payroll.payslip', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function payslipFilename(PayrollRecord $record): string
    {
        return 'payslip-'.Str::slug((string) ($record->employee?->employee_no ?: 'employee')).'.pdf';
    }
}
