<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\UpdateDocumentWorkflowPresetRequest;
use App\Models\DocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\UpdateDocumentWorkflowPreset;
use Illuminate\Http\RedirectResponse;

class UpdateDocumentWorkflowPresetController extends Controller
{
    public function __invoke(
        UpdateDocumentWorkflowPresetRequest $request,
        DocumentWorkflowPreset $workflowPreset,
        UpdateDocumentWorkflowPreset $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $workflowPreset->company_id === $companyId, 404);

        $action->handle(
            preset: $workflowPreset,
            actor: $request->user(),
            companyId: $companyId,
            name: (string) $request->validated('name'),
            description: $request->validated('description'),
            stages: $request->validated('stages'),
        );

        return back()->with('success', 'Workflow preset updated.');
    }
}
