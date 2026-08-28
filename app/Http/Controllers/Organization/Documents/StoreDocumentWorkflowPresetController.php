<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\StoreDocumentWorkflowPresetRequest;
use App\Support\Documents\Workflow\Actions\StoreDocumentWorkflowPreset;
use Illuminate\Http\RedirectResponse;

class StoreDocumentWorkflowPresetController extends Controller
{
    public function __invoke(
        StoreDocumentWorkflowPresetRequest $request,
        StoreDocumentWorkflowPreset $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $action->handle(
            actor: $request->user(),
            companyId: $companyId,
            name: (string) $request->validated('name'),
            description: $request->validated('description'),
            stages: $request->validated('stages'),
        );

        return back()->with('success', 'Workflow preset created.');
    }
}
