<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BranchDocument\StoreBranchDocumentRequest;
use App\Http\Requests\Organization\BranchDocument\UpdateBranchDocumentRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use App\Support\CompanyDocuments\CompanyDocumentQuery;
use App\Support\CompanyDocuments\CompanyDocumentStorage;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchDocumentController extends Controller
{
    use ResolvesPerPage;

    public function index(
        Request $request,
        Branch $branch,
        CompanyDocumentAccess $access,
        CompanyDocumentQuery $documents,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        $access->authorizeBranch($request->user(), $company, $branch, CompanyDocumentAccess::Abilities['view']);

        $search = $request->string('search')->trim()->toString();
        $documentTypeId = $request->integer('document_type') ?: null;
        $expiryStatus = in_array($request->query('expiry_status'), ['all', 'valid', 'expiring_soon', 'expired'], true)
            ? (string) $request->query('expiry_status')
            : 'all';

        $paginator = $documents->paginateForBranch(
            $company,
            $branch,
            $search,
            $documentTypeId,
            $expiryStatus,
            $this->resolvePerPage($request, default: 12, allowed: [12, 24, 48, 100]),
        );
        $items = $paginator->through(fn (CompanyDocument $document) => $documents->present($document, $branch));

        $can = $access->permissionsForBranch($request->user(), $company, $branch);

        return Inertia::render('organization/branch-documents', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'logo_url' => $company->logo ? asset('storage/'.$company->logo) : null],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
                'country' => $branch->country,
                'status' => $branch->status,
            ],
            'documents' => $items->items(),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => [
                'search' => $search,
                'document_type' => $documentTypeId,
                'expiry_status' => $expiryStatus,
            ],
            'summary' => $documents->summaryForBranch($company, $branch),
            'document_types' => DocumentType::query()->where('is_active', true)->orderBy('title')->get(['id', 'title']),
            'can' => $can,
        ]);
    }

    public function store(
        StoreBranchDocumentRequest $request,
        Branch $branch,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        $validated = $request->validated();
        $documentType = DocumentType::query()->whereKey($validated['document_type_id'])->where('is_active', true)->firstOrFail();

        $storage->create($company, $documentType, $request->file('file'), $validated, $request->user()?->id, $branch->id);

        return back()->with('success', 'Branch document uploaded.');
    }

    public function update(
        UpdateBranchDocumentRequest $request,
        Branch $branch,
        CompanyDocument $companyDocument,
    ): RedirectResponse {
        $data = $request->validated();

        foreach (['title', 'document_number', 'issue_date', 'expiry_date', 'notes'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $companyDocument->update($data);

        return back()->with('success', 'Branch document updated.');
    }

    public function destroy(
        Request $request,
        Branch $branch,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        $access->authorizeBranchDocument($request->user(), $company, $branch, $companyDocument, CompanyDocumentAccess::Abilities['delete']);

        $storage->delete($companyDocument);

        return back()->with('success', 'Branch document deleted.');
    }
}
