<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BankAccounts\ImportBankAccountsRequest;
use App\Support\BankAccounts\BankAccountImportOrchestrator;
use App\Support\BankAccounts\BankAccountImportTemplateExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BankAccountsImportController extends Controller
{
    public function importTemplate(Request $request, BankAccountImportTemplateExporter $exporter)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $result = $exporter->export($companyId);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importPreview(
        ImportBankAccountsRequest $request,
        BankAccountImportOrchestrator $orchestrator,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $result = $orchestrator->preview(
                $companyId,
                $request->file('file'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }

    public function import(
        ImportBankAccountsRequest $request,
        BankAccountImportOrchestrator $orchestrator,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $result = $orchestrator->execute(
                $companyId,
                $request->file('file'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.bank-accounts')
            ->with(
                'success',
                "Imported {$result['imported']} bank account(s). Skipped {$result['skipped']} row(s).",
            );
    }
}
