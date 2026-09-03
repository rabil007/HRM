<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Support\Documents\DocumentsModuleAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestPresenter;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestRosterQuery;
use App\Support\Documents\Signing\DocumentSigningPresetPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowPresenter;
use App\Support\Documents\Workflow\DocumentWorkflowPresetPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowRosterQuery;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentRequestsIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(
        Request $request,
        DocumentWorkflowRosterQuery $rosterQuery,
        DocumentWorkflowPresenter $presenter,
    ): InertiaResponse|RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $workflowPermissions = DocumentWorkflowPagePermissions::for($user);

        abort_unless(DocumentsModuleAccess::canViewRequests($user), 403);

        $canViewApprovals = $workflowPermissions['view'];
        $canViewSigning = DocumentsModuleAccess::canViewSigning($user);
        $requestedTab = $request->query('tab');

        if ($requestedTab === 'signatures') {
            if ($canViewSigning) {
                return redirect()->route('organization.documents.requests', ['tab' => 'recipient']);
            }

            if ($canViewApprovals) {
                return redirect()->route('organization.documents.requests', ['tab' => 'review']);
            }

            abort(403);
        }

        $tab = match ($requestedTab) {
            'recipient' => 'recipient',
            'review' => 'review',
            default => $canViewApprovals ? 'review' : 'recipient',
        };

        if ($tab === 'review') {
            abort_unless($canViewApprovals, 403);
        } else {
            abort_unless($canViewSigning, 403);
        }

        $perPage = $this->resolvePerPage($request);
        $page = max(1, (int) $request->query('page', 1));

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'action' => trim((string) $request->query('action', '')),
            'assigned_to_me' => $request->boolean('assigned_to_me'),
        ];

        if ($tab === 'review') {
            $paginator = $rosterQuery->paginate(
                $companyId,
                $filters,
                $perPage,
                $page,
                $user,
            );

            return Inertia::render('organization/documents/requests/index', [
                'tab' => 'review',
                'can' => $workflowPermissions,
                'preset_can' => DocumentWorkflowPresetPagePermissions::for($user),
                'signing_preset_can' => DocumentSigningPresetPagePermissions::for($user),
                'filters' => $filters,
                'search' => $filters['search'],
                'workflow_requests' => collect($paginator->items())
                    ->map(fn ($item) => $presenter->listItem($item))
                    ->values()
                    ->all(),
                'recipient_requests' => [],
                'pagination' => $this->paginationMeta($paginator),
            ]);
        }

        $recipientPresenter = app(DocumentRecipientRequestPresenter::class);
        $recipientPaginator = app(DocumentRecipientRequestRosterQuery::class)->paginate(
            $companyId,
            $filters,
            $perPage,
            $page,
            $user,
        );

        return Inertia::render('organization/documents/requests/index', [
            'tab' => 'recipient',
            'can' => $workflowPermissions,
            'preset_can' => DocumentWorkflowPresetPagePermissions::for($user),
            'signing_preset_can' => DocumentSigningPresetPagePermissions::for($user),
            'filters' => $filters,
            'search' => $filters['search'],
            'workflow_requests' => [],
            'recipient_requests' => collect($recipientPaginator->items())
                ->map(fn ($item) => $recipientPresenter->listItem($item))
                ->values()
                ->all(),
            'recipient_automation' => DocumentRecipientAutomationSettingController::propsFor(
                $user,
                $companyId,
            ),
            'pagination' => $this->paginationMeta($recipientPaginator),
        ]);
    }
}
