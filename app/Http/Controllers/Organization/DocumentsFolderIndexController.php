<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentDepartmentTree;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentsFolderIndexController extends Controller
{
    public function __invoke(Request $request, DocumentBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $search = trim((string) $request->query('search', ''));
        $expiry = (string) $request->query('expiry', 'all');
        $departmentId = trim((string) $request->query('department_id', ''));

        if (! DocumentExpiry::isValidFilter($expiry)) {
            $expiry = 'all';
        }

        $directoryFilters = new EmployeeDirectoryFilters(departmentId: $departmentId);
        $summary = $browse->expirySummary($companyId, departmentId: $departmentId);
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $payload = [
            'summary' => $summary,
            'expiry' => $expiry,
            'search' => $search,
            'department_id' => $departmentId,
            'department_tree' => DocumentDepartmentTree::for($companyId, $directoryFilters),
            'department_tree_selected_id' => $departmentId !== '' ? (int) $departmentId : null,
            'employees' => [],
            'searchDocuments' => null,
            'complianceDocuments' => null,
            'document_types' => EmployeeFormOptions::documentTypes(),
            'can' => DocumentPagePermissions::for($request->user()),
        ];

        if ($expiry === 'all') {
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
