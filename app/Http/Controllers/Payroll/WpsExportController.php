<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\WpsStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ExportWpsRequest;
use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Wps\WpsExportPreview;
use App\Support\Payroll\Wps\WpsExportValidator;
use App\Support\Payroll\Wps\WpsSifExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WpsExportController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $periodId = (int) $request->query('period_id', 0);

        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->limit(50)
            ->get(['id', 'name', 'start_date', 'end_date', 'status'])
            ->map(fn (PayrollPeriod $period) => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
                'status' => $period->status->value,
            ]);

        $preview = null;

        if ($periodId > 0) {
            $period = PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->findOrFail($periodId);

            $company = Company::query()->findOrFail($companyId);
            $preview = app(WpsExportPreview::class)->forPeriod($company, $period);
        }

        return Inertia::render('payroll/wps', [
            'periods' => $periods,
            'selected_period_id' => $periodId > 0 ? $periodId : null,
            'preview' => $preview,
            'permissions' => [
                'export' => $request->user()?->can('payroll.wps.export') ?? false,
            ],
        ]);
    }

    public function export(
        ExportWpsRequest $request,
        WpsExportValidator $validator,
        WpsSifExporter $exporter,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $period = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $request->validated('period_id'));

        $company = Company::query()->findOrFail($companyId);
        $records = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->with(['employee.primaryBankAccount.bank'])
            ->orderBy('id')
            ->get();

        $partition = $validator->partition($company, $period, $records);

        abort_if($partition['eligible']->isEmpty(), 422, 'No eligible payroll records found for WPS export.');

        $reference = $exporter->makeReference($company, $period);
        $content = $exporter->export($company, $period, $partition['eligible'], $reference);
        $filename = Str::slug($company->slug.'-wps-'.$period->id).'.sif';

        PayrollRecord::query()
            ->whereIn('id', $partition['eligible']->pluck('id'))
            ->update([
                'wps_status' => WpsStatus::Submitted->value,
                'wps_submitted_at' => now(),
                'wps_reference' => $reference,
                'wps_agent_ref' => $company->wps_agent_code,
            ]);

        return response()->streamDownload(
            fn () => print ($content),
            $filename,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
