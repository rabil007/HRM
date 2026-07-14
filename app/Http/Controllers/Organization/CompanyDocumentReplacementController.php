<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompanyDocument\ReplaceCompanyDocumentRequest;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use App\Support\CompanyDocuments\CompanyDocumentStorage;
use Illuminate\Http\RedirectResponse;

class CompanyDocumentReplacementController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        ReplaceCompanyDocumentRequest $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['update']);
        abort_unless((int) $companyDocument->company_id === $company->id, 404);

        $storage->replace($companyDocument, $request->file('file'), $request->user()?->id);

        return back()->with('success', 'Company document file replaced.');
    }
}
