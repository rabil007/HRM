<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PayrollCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\BulkPayslipActionRequest;
use App\Models\PayrollRecord;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\Actions\GeneratePayslip;
use App\Support\Payroll\Actions\SendPayslipEmails;
use App\Support\Payroll\PayslipData;
use App\Support\Payroll\PayslipListResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayslipController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $periodId = trim((string) $request->query('period_id', ''));
        $status = trim((string) $request->query('status', ''));
        $hasPayslip = trim((string) $request->query('has_payslip', ''));

        $query = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->with(['employee', 'period'])
            ->orderByDesc('id');

        $this->applyFilters($query, $search, $category, $periodId, $status, $hasPayslip);

        $paginator = $query->paginate($perPage)->withQueryString();

        return Inertia::render('payroll/payslips', [
            'records' => collect($paginator->items())
                ->map(fn (PayrollRecord $record) => PayslipListResource::toArray($record))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'category' => $category,
                'period_id' => $periodId,
                'status' => $status,
                'has_payslip' => $hasPayslip,
            ],
            'payroll_categories' => collect(PayrollCategory::cases())
                ->map(fn (PayrollCategory $item) => ['value' => $item->value, 'label' => $item->label()])
                ->values()
                ->all(),
            'status_options' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'paid', 'label' => 'Paid'],
            ],
            'permissions' => [
                'generate' => $request->user()?->can('payroll.payslips.generate') ?? false,
                'email' => $request->user()?->can('payroll.payslips.email') ?? false,
            ],
        ]);
    }

    public function show(Request $request, PayrollRecord $payrollRecord)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollRecord->company_id === $companyId, 404);

        $data = PayslipData::for($payrollRecord, $companyId);
        $data['printable'] = true;
        $data['is_pdf'] = $request->query('format') === 'pdf';

        if ($data['is_pdf']) {
            return $this->streamPdf($payrollRecord, $data);
        }

        return view('payroll.payslip', $data);
    }

    public function download(Request $request, PayrollRecord $payrollRecord): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollRecord->company_id === $companyId, 404);

        if (filled($payrollRecord->payslip_path) && Storage::disk('local')->exists($payrollRecord->payslip_path)) {
            $filename = basename($payrollRecord->payslip_path);

            return Storage::disk('local')->download($payrollRecord->payslip_path, $filename);
        }

        $data = PayslipData::for($payrollRecord, $companyId);
        $data['printable'] = false;
        $data['is_pdf'] = true;

        return $this->streamPdf($payrollRecord, $data);
    }

    public function generate(
        BulkPayslipActionRequest $request,
        GeneratePayslip $generatePayslip,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $records = $this->resolveRecords($companyId, $request->validated());

        $generated = 0;

        foreach ($records as $record) {
            $generatePayslip->handle($record);
            $generated++;
        }

        return back()->with('success', "Generated {$generated} payslip(s).");
    }

    public function email(
        BulkPayslipActionRequest $request,
        SendPayslipEmails $sendPayslipEmails,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $records = $this->resolveRecords($companyId, $request->validated());
        $result = $sendPayslipEmails->handle($records);

        $message = "Queued {$result['sent']} payslip email(s).";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped.";
        }

        return back()->with('success', $message);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Collection<int, PayrollRecord>
     */
    private function resolveRecords(int $companyId, array $validated)
    {
        if (! empty($validated['record_ids'])) {
            return PayrollRecord::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $validated['record_ids'])
                ->get();
        }

        $query = PayrollRecord::query()->where('company_id', $companyId);

        if (! empty($validated['period_id'])) {
            $query->where('period_id', (int) $validated['period_id']);
        }

        return $query->get();
    }

    private function applyFilters(
        Builder $query,
        string $search,
        string $category,
        string $periodId,
        string $status,
        string $hasPayslip,
    ): void {
        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->whereHas('employee', function (Builder $employeeQuery) use ($term): void {
                $employeeQuery
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(employee_no) LIKE ?', [$term]);
            });
        }

        if (in_array($category, [PayrollCategory::Crew->value, PayrollCategory::Office->value], true)) {
            $query->where('payroll_category', $category);
        }

        if ($periodId !== '' && ctype_digit($periodId)) {
            $query->where('period_id', (int) $periodId);
        }

        if (in_array($status, ['draft', 'approved', 'paid'], true)) {
            $query->where('status', $status);
        }

        if ($hasPayslip === 'yes') {
            $query->whereNotNull('payslip_path');
        }

        if ($hasPayslip === 'no') {
            $query->whereNull('payslip_path');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function streamPdf(PayrollRecord $record, array $data): StreamedResponse
    {
        $record->loadMissing('employee');
        $filename = 'payslip-'.Str::slug((string) ($record->employee?->employee_no ?: 'employee')).'.pdf';

        $pdf = Pdf::loadView('payroll.payslip', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
