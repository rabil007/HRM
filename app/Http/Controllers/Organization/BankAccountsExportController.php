<?php

namespace App\Http\Controllers\Organization;

use App\Exports\BankAccountsExport;
use App\Http\Controllers\Controller;
use App\Support\BankAccounts\BankAccountDirectoryFilters;
use App\Support\BankAccounts\BankAccountDirectoryQuery;
use App\Support\Organization\SelectedRecordIds;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class BankAccountsExportController extends Controller
{
    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = BankAccountDirectoryFilters::fromRequest($request);

        $query = (new BankAccountDirectoryQuery($companyId, $filters))->exportQuery();
        $selectedIds = SelectedRecordIds::fromRequest($request);

        if ($selectedIds !== []) {
            $query->whereKey($selectedIds);
        }

        $export = new BankAccountsExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "bank_accounts_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $bankAccounts = $query->get();
            $pdf = Pdf::loadView('exports.bank-accounts', [
                'bankAccounts' => $bankAccounts,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
