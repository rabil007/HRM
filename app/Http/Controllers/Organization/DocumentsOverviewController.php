<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Documents\DocumentsLibraryQueryState;
use App\Support\Documents\DocumentsModuleAccess;
use App\Support\Documents\DocumentsOverviewQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentsOverviewController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentsOverviewQuery $overview,
    ): InertiaResponse|RedirectResponse {
        $libraryQuery = DocumentsLibraryQueryState::fromRequest($request);

        if ($libraryQuery->hasBrowseState()) {
            return redirect()->route('organization.documents.library', $libraryQuery->toQuery());
        }

        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('organization/documents/overview', [
            ...$overview->forCompany($companyId),
            'sections' => DocumentsModuleAccess::sections($request->user()),
        ]);
    }
}
