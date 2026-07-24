<?php

namespace App\Http\Controllers\Organization;

use App\Exports\SeaServicesExport;
use App\Http\Controllers\Controller;
use App\Support\Organization\SelectedRecordIds;
use App\Support\SeaServices\SeaServiceDirectoryFilters;
use App\Support\SeaServices\SeaServiceDirectoryQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class SeaServicesExportController extends Controller
{
    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = SeaServiceDirectoryFilters::fromRequest($request);

        $query = (new SeaServiceDirectoryQuery($companyId, $filters))->exportQuery();
        $selectedIds = SelectedRecordIds::fromRequest($request);

        if ($selectedIds !== []) {
            $query->whereKey($selectedIds);
        }

        $export = new SeaServicesExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "sea_services_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $seaServices = $query->get();
            $pdf = Pdf::loadView('exports.sea-services', [
                'seaServices' => $seaServices,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
