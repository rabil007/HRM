<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentLifecycleAutomation;
use App\Models\EmployeeDocument;
use App\Support\Documents\Lifecycle\Actions\RetryDocumentLifecycleAutomation;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetryDocumentLifecycleAutomationController extends Controller
{
    public function __invoke(
        Request $request,
        EmployeeDocument $document,
        RetryDocumentLifecycleAutomation $action,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.recipient-requests.create') ?? false, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $lifecycle = DocumentLifecycleAutomation::query()
            ->forCompany($companyId)
            ->whereHas('documentInstance', function ($query) use ($document, $companyId): void {
                $query->where('employee_document_id', $document->id)
                    ->where('company_id', $companyId);
            })
            ->firstOrFail();

        $action->handle($lifecycle, $request->user(), $companyId);

        return back()->with('success', 'Lifecycle automation retry attempted.');
    }
}
