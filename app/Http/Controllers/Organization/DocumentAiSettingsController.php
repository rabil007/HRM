<?php

namespace App\Http\Controllers\Organization;

use App\Enums\DocumentAiMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\UpdateDocumentAiSettingsRequest;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Illuminate\Http\RedirectResponse;

class DocumentAiSettingsController extends Controller
{
    public function update(
        UpdateDocumentAiSettingsRequest $request,
        DocumentAiSettings $settings,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $mode = DocumentAiMode::from((string) $request->validated('mode'));

        $settings->updateForCompany($companyId, $mode, $request->user());

        return back()->with('success', 'Document AI settings updated.');
    }
}
