<?php

namespace App\Http\Controllers\Organization;

use App\Exports\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ExportEmployeesRequest;
use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeExportFieldRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeExportController extends Controller
{
    /**
     * @var list<string>
     */
    private const EXPORT_RELATIONS = [
        'branch:id,name',
        'department:id,name',
        'position:id,title',
        'rank:id,name',
        'project:id,name',
        'client:id,name',
        'genderRef:id,name',
        'religionRef:id,name',
        'nationalityRef:id,name,code',
        'visaTypeRef:id,name',
        'companyVisaTypeRef:id,name',
        'currentContract',
        'primaryBankAccount.bank:id,name',
    ];

    public function export(Request $request)
    {
        return $this->downloadExport(
            $request,
            EmployeeExportFieldRegistry::DEFAULT_FIELD_KEYS,
            strtolower((string) $request->query('format', 'csv')),
        );
    }

    public function exportSelected(ExportEmployeesRequest $request)
    {
        $fields = $request->sanitizedFields();

        if ($fields === []) {
            abort(422, 'Select at least one allowed export field.');
        }

        return $this->downloadExport(
            $request,
            $fields,
            strtolower((string) $request->validated('format')),
        );
    }

    /**
     * @param  list<string>  $fields
     */
    private function downloadExport(Request $request, array $fields, string $format)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $directoryFilters = EmployeeDirectoryFilters::fromRequest($request);

        $query = (new EmployeeDirectoryQuery($companyId, $directoryFilters))
            ->apply(
                Employee::query()->with(self::EXPORT_RELATIONS),
            );

        $export = new EmployeesExport($query, $fields);

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
