<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\UpdateDocumentAiExtractionPreferenceRequest;
use App\Support\EmployeeDocuments\UpdateDocumentAiExtractionPreference;
use Illuminate\Http\RedirectResponse;

class DocumentAiExtractionPreferenceController extends Controller
{
    public function update(
        UpdateDocumentAiExtractionPreferenceRequest $request,
        UpdateDocumentAiExtractionPreference $update,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $update->handle($user, $request->enabled());

        return back();
    }
}
