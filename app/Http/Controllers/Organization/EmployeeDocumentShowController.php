<?php

namespace App\Http\Controllers\Organization;

use App\Enums\RecentItemType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEligibility;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestPagePermissions;
use App\Support\Documents\RecipientRequests\DocumentRecipientSignatoryOptionsQuery;
use App\Support\Documents\Workflow\DocumentWorkflowEligibility;
use App\Support\Documents\Workflow\DocumentWorkflowPresenter;
use App\Support\Documents\Workflow\DocumentWorkflowPresetQuery;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use App\Support\EmployeeDocuments\DocumentShowBackNavigation;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\RecentItems\RecordRecentItem;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeDocumentShowController extends Controller
{
    public function __invoke(Request $request, Employee $employee, EmployeeDocument $document, RecordRecentItem $recordRecentItem)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $user = $request->user();
        if ($user !== null) {
            $recordRecentItem->handle($user, $companyId, RecentItemType::Document, $document->id);
        }

        $document->load([
            'documentType:id,title',
            'uploader:id,name',
            'versions.replacer:id,name',
            'documentInstance.currentVersion',
            'documentInstance.generatedBy:id,name',
        ]);

        $workflowPresenter = app(DocumentWorkflowPresenter::class);
        $workflowEligibility = app(DocumentWorkflowEligibility::class);

        $workflowSummary = $document->documentInstance !== null
            ? $workflowPresenter->documentShowWorkflowSummary($document->documentInstance)
            : null;

        $canCreateWorkflow = ($user?->can('documents.requests.create') ?? false)
            && $workflowEligibility->canCreateForDocument($document, $companyId);

        $workflowPresets = $canCreateWorkflow
            ? app(DocumentWorkflowPresetQuery::class)->activeForCompany($companyId)
            : [];

        $recipientPermissions = DocumentRecipientRequestPagePermissions::for($user);
        $recipientEligibility = app(DocumentRecipientRequestEligibility::class)
            ->forDocument($document, $companyId);

        return Inertia::render('organization/documents/show', [
            'document' => $document->toShowArray(),
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'email' => $employee->work_email ?: $employee->personal_email,
                'phone' => $employee->phone,
            ],
            'countries' => EmployeeFormOptions::for($companyId)['countries'],
            'document_types' => EmployeeFormOptions::documentTypes(),
            'can' => DocumentPagePermissions::for($request->user()),
            'workflow' => [
                'summary' => $workflowSummary,
                'can_create' => $canCreateWorkflow,
                'assignee_options' => $canCreateWorkflow
                    ? $workflowEligibility->assigneeOptions($companyId)
                    : [],
                'presets' => $workflowPresets,
            ],
            'recipient_request' => [
                'can' => $recipientPermissions,
                'can_request_sign' => $recipientPermissions['create'] && $recipientEligibility['can_request_sign'],
                'can_request_acknowledge' => $recipientPermissions['create'] && $recipientEligibility['can_request_acknowledge'],
                'can_request_manager_countersign' => $recipientPermissions['create'] && $recipientEligibility['can_request_manager_countersign'],
                'can_request_company_countersign' => $recipientPermissions['create'] && $recipientEligibility['can_request_company_countersign'],
                'sign_blocked_reason' => $recipientEligibility['sign_blocked_reason'],
                'acknowledge_blocked_reason' => $recipientEligibility['acknowledge_blocked_reason'],
                'manager_countersign_blocked_reason' => $recipientEligibility['manager_countersign_blocked_reason'],
                'company_countersign_blocked_reason' => $recipientEligibility['company_countersign_blocked_reason'],
                'resolved_manager' => $recipientEligibility['resolved_manager'],
                'signatory_options' => $recipientEligibility['can_request_company_countersign']
                    ? app(DocumentRecipientSignatoryOptionsQuery::class)->forCompany($companyId)
                    : [],
                'current_source_version' => $document->documentInstance?->currentVersion?->version,
            ],
            'back' => DocumentShowBackNavigation::resolve($request, $employee),
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                EmployeeDocument::class,
                $document->id,
                limit: 20,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }
}
