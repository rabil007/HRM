<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentWorkflowPreset;
use App\Support\Documents\Workflow\DocumentWorkflowPresetFormOptions;
use App\Support\Documents\Workflow\DocumentWorkflowPresetPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowPresetPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentWorkflowPresetsIndexController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentWorkflowPresetPresenter $presenter,
        DocumentWorkflowPresetFormOptions $formOptions,
    ): Response {
        abort_unless($request->user()?->can('documents.workflow-presets.view') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        $presets = DocumentWorkflowPreset::query()
            ->forCompany($companyId)
            ->withCount('stages')
            ->with([
                'stages.targets.targetUser:id,name',
                'stages.targets.targetRole:id,name',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentWorkflowPreset $preset): array => $presenter->listItem($preset))
            ->all();

        return Inertia::render('organization/documents/workflow-presets/index', [
            'presets' => $presets,
            'can' => DocumentWorkflowPresetPagePermissions::for($request->user()),
            'form_options' => [
                'users' => $formOptions->users($companyId),
                'roles' => $formOptions->roles($companyId),
                'target_types' => $formOptions->targetTypes(),
            ],
        ]);
    }
}
