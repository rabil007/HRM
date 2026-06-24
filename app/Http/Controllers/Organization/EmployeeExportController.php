<?php

namespace App\Http\Controllers\Organization;

use App\Exports\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeExportController extends Controller
{
    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        $companyId = (int) $request->attributes->get('current_company_id');
        $directoryFilters = EmployeeDirectoryFilters::fromRequest($request);

        $query = (new EmployeeDirectoryQuery($companyId, $directoryFilters))
            ->apply(
                Employee::query()->with([
                    'branch:id,name',
                    'department:id,name',
                    'position:id,title',
                    'user:id,name,email',
                    'currentContract',
                ]),
            );

        $export = new EmployeesExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "employees_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $employees = $query->get();
            $pdf = Pdf::loadView('exports.employees', [
                'employees' => $employees,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
