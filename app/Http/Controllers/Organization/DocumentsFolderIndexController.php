<?php

namespace App\Http\Controllers\Organization;

use App\Enums\SavedViewPage;
use App\Http\Controllers\Controller;
use App\Support\Documents\DocumentsLibraryQueryState;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;
use App\Support\EmployeeDocuments\DocumentDepartmentTree;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\SavedViews\ApplyDefaultSavedView;
use App\Support\SavedViews\SavedViewsForPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentsFolderIndexController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentBrowseQuery $browse,
        DocumentComplianceQuery $compliance,
    ): InertiaResponse|RedirectResponse {
        $redirect = ApplyDefaultSavedView::maybeRedirect($request, SavedViewPage::Documents);

        if ($redirect !== null) {
            return $redirect;
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $libraryQuery = DocumentsLibraryQueryState::fromRequest($request);
        $search = $libraryQuery->search;
        $expiry = $libraryQuery->expiry;
        $departmentId = $libraryQuery->departmentId;
        $requirementStatus = $libraryQuery->requirementStatus;
        $documentTypeId = $libraryQuery->documentTypeId !== ''
            ? (int) $libraryQuery->documentTypeId
            : null;

        $directoryFilters = new EmployeeDirectoryFilters(departmentId: $departmentId);
        $summary = $browse->expirySummary($companyId, departmentId: $departmentId);
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $payload = [
            'summary' => $summary,
            'requirement_summary' => $compliance->summary($companyId, $departmentId),
            'expiry' => $expiry,
            'requirement_status' => $requirementStatus,
            'search' => $search,
            'department_id' => $departmentId,
            'document_type_id' => $libraryQuery->documentTypeId,
            'department_tree' => DocumentDepartmentTree::for($companyId, $directoryFilters),
            'department_tree_selected_id' => $departmentId !== '' ? (int) $departmentId : null,
            'employees' => [],
            'searchDocuments' => null,
            'complianceDocuments' => null,
            'requirementDocuments' => null,
            'document_types' => EmployeeFormOptions::documentTypes(),
            'countries' => EmployeeFormOptions::for($companyId)['countries'],
            'can' => DocumentPagePermissions::for($request->user()),
            'saved_views' => SavedViewsForPage::props($request->user(), $companyId, SavedViewPage::Documents),
            'module_section' => 'library',
        ];

        if ($requirementStatus !== '') {
            $payload['requirementDocuments'] = $compliance->paginate(
                $companyId,
                $requirementStatus,
                $search !== '' ? $search : null,
                $perPage,
                $departmentId,
                $documentTypeId,
            );
        } elseif ($expiry !== 'all') {
            $payload['complianceDocuments'] = $browse->documentsForCompliance(
                $companyId,
                $expiry,
                $search !== '' ? $search : null,
                $perPage,
                $departmentId,
                $documentTypeId,
            );
        } elseif ($documentTypeId !== null) {
            $payload['requirementDocuments'] = $compliance->paginate(
                $companyId,
                'required',
                $search !== '' ? $search : null,
                $perPage,
                $departmentId,
                $documentTypeId,
            );
        } else {
            $payload['employees'] = $browse->employeesWithDocuments(
                $companyId,
                $search !== '' ? $search : null,
                $departmentId,
            )->values()->all();

            if ($search !== '') {
                $payload['searchDocuments'] = $browse->documentsForSearch(
                    $companyId,
                    $search,
                    $perPage,
                    $departmentId,
                );
            }
        }

        return Inertia::render('organization/documents/index', $payload);
    }
}
