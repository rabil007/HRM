<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentGenerationTemplate\StoreDocumentGenerationTemplateRequest;
use App\Http\Requests\Organization\DocumentGenerationTemplate\UpdateDocumentGenerationTemplateRequest;
use App\Models\DocumentGenerationTemplate;
use App\Support\Documents\Actions\CreateDocumentGenerationTemplate;
use App\Support\Documents\Actions\DuplicateDocumentGenerationTemplate;
use App\Support\Documents\Actions\UpdateDocumentGenerationTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentGenerationTemplateController extends Controller
{
    public function store(
        StoreDocumentGenerationTemplateRequest $request,
        CreateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);

        $action->handle($companyId, $request->validated(), $request->user());

        return back()->with('success', 'Template created.');
    }

    public function update(
        UpdateDocumentGenerationTemplateRequest $request,
        DocumentGenerationTemplate $template,
        UpdateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $action->handle($template, $request->validated(), $request->user());

        return back()->with('success', 'Template updated.');
    }

    public function duplicate(
        Request $request,
        DocumentGenerationTemplate $template,
        DuplicateDocumentGenerationTemplate $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.update') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $action->handle($template, $request->user());

        return back()->with('success', 'Template duplicated.');
    }

    public function destroy(
        Request $request,
        DocumentGenerationTemplate $template,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.templates.delete') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);
        abort_unless((int) $template->company_id === $companyId, 404);

        $template->delete();

        return back()->with('success', 'Template deleted.');
    }
}
