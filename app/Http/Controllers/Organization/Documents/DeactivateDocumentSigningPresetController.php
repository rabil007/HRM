<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentSigningPreset;
use App\Support\Documents\Signing\Actions\DeactivateDocumentSigningPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeactivateDocumentSigningPresetController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentSigningPreset $signingPreset,
        DeactivateDocumentSigningPreset $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.signing-presets.update') ?? false, 403);
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $signingPreset->company_id === $companyId, 404);

        $action->handle($signingPreset, $request->user(), $companyId);

        return back()->with('success', 'Signing preset deactivated.');
    }
}
