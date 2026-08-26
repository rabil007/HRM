<?php

namespace App\Http\Controllers\Organization;

use App\Enums\SavedViewPage;
use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;
use App\Support\EmployeeDocuments\DocumentDepartmentTree;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use App\Support\EmployeeDocuments\DocumentRequirementComplianceStatus;
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
        $search = trim((string) $request->query('search', ''));
        $expiry = (string) $request->query('expiry', 'all');
        $departmentId = trim((string) $request->query('department_id', ''));
        $requirementStatus = trim((string) $request->query('requirement_status', ''));

        if (! DocumentExpiry::isValidFilter($expiry)) {
            $expiry = 'all';
        }

        if ($requirementStatus !== '' && ! DocumentRequirementComplianceStatus::isValidFilter($requirementStatus)) {
            $requirementStatus = '';
        }

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
        ];

        if ($requirementStatus !== '') {
            $payload['requirementDocuments'] = $compliance->paginate(
                $companyId,
                $requirementStatus,
                $search !== '' ? $search : null,
                $perPage,
                $departmentId,
            );
        } elseif ($expiry === 'all') {
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
        } else {
            $payload['complianceDocuments'] = $browse->documentsForCompliance(
                $companyId,
                $expiry,
                $search !== '' ? $search : null,
                $perPage,
                $departmentId,
            );
        }

        return Inertia::render('organization/documents/index', $payload);
    }
}
