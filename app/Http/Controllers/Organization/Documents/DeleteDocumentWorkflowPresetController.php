<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\DeleteDocumentWorkflowPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeleteDocumentWorkflowPresetController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentWorkflowPreset $workflowPreset,
        DeleteDocumentWorkflowPreset $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.workflow-presets.delete') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $workflowPreset->company_id === $companyId, 404);

        try {
            $action->handle($workflowPreset, $request->user(), $companyId);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Workflow preset deleted.');
    }
}
