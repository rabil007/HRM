<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\UpdateDocumentSigningPresetRequest;
use App\Models\DocumentSigningPreset;
use App\Support\Documents\Signing\Actions\UpdateDocumentSigningPreset;
use Illuminate\Http\RedirectResponse;

class UpdateDocumentSigningPresetController extends Controller
{
    public function __invoke(
        UpdateDocumentSigningPresetRequest $request,
        DocumentSigningPreset $signingPreset,
        UpdateDocumentSigningPreset $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $signingPreset->company_id === $companyId, 404);

        $action->handle(
            preset: $signingPreset,
            actor: $request->user(),
            companyId: $companyId,
            name: (string) $request->validated('name'),
            description: $request->validated('description'),
            steps: $request->validated('steps'),
        );

        return back()->with('success', 'Signing preset updated.');
    }
}
