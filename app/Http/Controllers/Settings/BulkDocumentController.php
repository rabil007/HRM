<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateSalaryDeclarationsJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BulkDocumentController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;

        GenerateSalaryDeclarationsJob::dispatch($companyId, $userId);

        return back()->with('success', 'Salary declaration generation started. Check Job Runs for progress.');
    }
}
