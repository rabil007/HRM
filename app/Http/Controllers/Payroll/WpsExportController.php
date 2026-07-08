<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\WpsStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ExportWpsRequest;
use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Wps\WpsExcelExporter;
use App\Support\Payroll\Wps\WpsExportValidator;
use App\Support\Payroll\Wps\WpsSifExporter;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WpsExportController extends Controller
{
    public function export(
        ExportWpsRequest $request,
        WpsExportValidator $validator,
        WpsSifExporter $sifExporter,
        WpsExcelExporter $excelExporter,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $period = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $request->validated('period_id'));

        $company = Company::query()->findOrFail($companyId);
        $recordsQuery = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->with(['employee.currentContract', 'employee.contracts', 'employee.primaryBankAccount.bank'])
            ->orderBy('id');

        if ($request->filled('record_ids')) {
            $recordsQuery->whereIn('id', $request->validated('record_ids'));
        }

        $records = $recordsQuery->get();

        $partition = $validator->partition($company, $period, $records);

        abort_if($partition['eligible']->isEmpty(), 422, 'No eligible payroll records found for WPS export.');

        $format = (string) $request->validated('format');
        $reference = $sifExporter->makeReference($company, $period);
        $now = now($company->timezone ?? config('app.timezone'));
        $filename = sprintf(
            '%s%s%s.%s',
            $company->wps_mol_uid,
            $now->format('ymd'),
            $now->format('His'),
            $format === 'xlsx' ? 'xlsx' : 'sif',
        );

        if ($format === 'xlsx') {
            $content = $excelExporter->export($company, $period, $partition['eligible'], $reference);
            $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $content = $sifExporter->export($company, $period, $partition['eligible'], $reference);
            $contentType = 'text/plain; charset=UTF-8';
        }

        $this->markRecordsSubmitted($partition['eligible'], $reference, $company->wps_agent_code);

        return response()->streamDownload(
            fn () => print ($content),
            $filename,
            ['Content-Type' => $contentType],
        );
    }

    /**
     * @param  Collection<int, PayrollRecord>  $eligible
     */
    private function markRecordsSubmitted(Collection $eligible, string $reference, ?string $agentCode): void
    {
        PayrollRecord::query()
            ->whereIn('id', $eligible->pluck('id'))
            ->update([
                'wps_status' => WpsStatus::Submitted->value,
                'wps_submitted_at' => now(),
                'wps_reference' => $reference,
                'wps_agent_ref' => $agentCode,
            ]);
    }
}
