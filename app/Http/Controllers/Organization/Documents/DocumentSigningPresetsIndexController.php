<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentSigningPreset;
use App\Support\Documents\Signing\DocumentSigningPresetFormOptions;
use App\Support\Documents\Signing\DocumentSigningPresetPagePermissions;
use App\Support\Documents\Signing\DocumentSigningPresetPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentSigningPresetsIndexController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentSigningPresetPresenter $presenter,
        DocumentSigningPresetFormOptions $formOptions,
    ): Response {
        abort_unless($request->user()?->can('documents.signing-presets.view') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        $presets = DocumentSigningPreset::query()
            ->forCompany($companyId)
            ->with(['steps.targetUser:id,name,email'])
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentSigningPreset $preset): array => $presenter->detail($preset))
            ->all();

        return Inertia::render('organization/documents/signing-presets/index', [
            'presets' => $presets,
            'can' => DocumentSigningPresetPagePermissions::for($request->user()),
            'form_options' => $formOptions->forCompany($companyId),
        ]);
    }
}
