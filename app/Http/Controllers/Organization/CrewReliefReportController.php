<?php

namespace App\Http\Controllers\Organization;

use App\Exports\CrewReliefExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewReliefReportRequest;
use App\Support\Reports\CrewRelief\CrewReliefReportFilters;
use App\Support\Reports\CrewRelief\CrewReliefReportPagePermissions;
use App\Support\Reports\CrewRelief\CrewReliefReportQuery;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CrewReliefReportController extends Controller
{
    public function index(CrewReliefReportRequest $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = CrewReliefReportFilters::fromRequest($request);
        $page = (int) $request->query('page', 1);

        $query = new CrewReliefReportQuery($companyId, $filters, $request->user());
        $result = $query->page($page, $request->url(), $request->query());

        return Inertia::render('organization/reports/crew-relief/index', [
            'rows' => $result['rows'],
            'pagination' => $result['pagination'],
            'summary' => $result['summary'],
            'filters' => $result['filters'],
            'filter_options' => $result['filter_options'],
            'can' => CrewReliefReportPagePermissions::for($request->user()),
        ]);
    }

    public function export(CrewReliefReportRequest $request): BinaryFileResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = CrewReliefReportFilters::fromRequest($request);

        $query = new CrewReliefReportQuery($companyId, $filters, $request->user());
        $export = new CrewReliefExport($query->exportCollection());

        $filename = 'crew-relief-report-'.now()->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }
}
