<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSalaryDeclarationsJob;
use App\Support\BulkDocuments\ClearSalaryDeclarations;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkDocumentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;

        GenerateSalaryDeclarationsJob::dispatch($companyId, $userId);

        return back()->with('success', 'Salary declaration generation started. Check Job Runs for progress.');
    }

    public function destroy(Request $request, ClearSalaryDeclarations $clear): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $deleted = $clear->handle($companyId);

        return back()->with(
            'success',
            $deleted > 0
                ? "Removed {$deleted} salary declaration document(s)."
                : 'No salary declaration documents found to remove.',
        );
    }

    public function download(Request $request, DocumentDownloadService $downloads): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $downloads->streamSalaryDeclarationsZip($companyId);
    }
}
