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
        'project:id,title',
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

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "employees_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            $export = new EmployeesExport($query, $this->withUsdAfterAed($fields));

            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        $export = new EmployeesExport($query, $fields);

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

    /**
     * For XLSX exports: automatically insert `contract_total_compensation_usd`
     * immediately after `contract_total_compensation_aed` when the AED field
     * is selected but the USD field was not explicitly added.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function withUsdAfterAed(array $fields): array
    {
        $aedKey = 'contract_total_compensation_aed';
        $usdKey = 'contract_total_compensation_usd';

        if (! in_array($aedKey, $fields, true) || in_array($usdKey, $fields, true)) {
            return $fields;
        }

        $result = [];

        foreach ($fields as $field) {
            $result[] = $field;

            if ($field === $aedKey) {
                $result[] = $usdKey;
            }
        }

        return $result;
    }
}
