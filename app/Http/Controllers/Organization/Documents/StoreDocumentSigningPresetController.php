<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StoreDocumentSigningPresetRequest;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use Illuminate\Http\RedirectResponse;

class StoreDocumentSigningPresetController extends Controller
{
    public function __invoke(
        StoreDocumentSigningPresetRequest $request,
        StoreDocumentSigningPreset $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $action->handle(
            actor: $request->user(),
            companyId: $companyId,
            name: (string) $request->validated('name'),
            description: $request->validated('description'),
            steps: $request->validated('steps'),
        );

        return back()->with('success', 'Signing preset created.');
    }
}
