<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BranchDocument\ReplaceBranchDocumentRequest;
use App\Models\Branch;
use App\Models\CompanyDocument;
use App\Support\CompanyDocuments\CompanyDocumentStorage;
use Illuminate\Http\RedirectResponse;

class BranchDocumentReplacementController extends Controller
{
    public function __invoke(
        ReplaceBranchDocumentRequest $request,
        Branch $branch,
        CompanyDocument $companyDocument,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $storage->replace($companyDocument, $request->file('file'), $request->user()?->id);

        return back()->with('success', 'Branch document file replaced.');
    }
}
