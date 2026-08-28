<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\ActivateDocumentWorkflowPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivateDocumentWorkflowPresetController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentWorkflowPreset $workflowPreset,
        ActivateDocumentWorkflowPreset $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.workflow-presets.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $workflowPreset->company_id === $companyId, 404);

        $action->handle($workflowPreset, $request->user(), $companyId);

        return back()->with('success', 'Workflow preset activated.');
    }
}
