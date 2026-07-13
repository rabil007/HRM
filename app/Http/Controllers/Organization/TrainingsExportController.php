<?php

namespace App\Http\Controllers\Organization;

use App\Exports\TrainingsExport;
use App\Http\Controllers\Controller;
use App\Support\EmployeeTrainings\TrainingDirectoryFilters;
use App\Support\EmployeeTrainings\TrainingDirectoryQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class TrainingsExportController extends Controller
{
    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = TrainingDirectoryFilters::fromRequest($request);

        $query = (new TrainingDirectoryQuery($companyId, $filters))->exportQuery();

        $export = new TrainingsExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "trainings_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $trainings = $query->get();
            $pdf = Pdf::loadView('exports.trainings', [
                'trainings' => $trainings,
                'generatedAt' => now(),
            ])->setPaper('a4', 'landscape');

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
