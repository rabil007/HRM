<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompanyDocument\StoreCompanyDocumentRequest;
use App\Http\Requests\Organization\CompanyDocument\UpdateCompanyDocumentRequest;
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

class CompanyDocumentController extends Controller
{
    use ResolvesPerPage;

    public function index(
        Request $request,
        Company $company,
        CompanyDocumentAccess $access,
        CompanyDocumentQuery $documents,
    ): Response {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['view']);

        $search = $request->string('search')->trim()->toString();
        $documentTypeId = $request->integer('document_type') ?: null;
        $expiryStatus = in_array($request->query('expiry_status'), ['all', 'valid', 'expiring_soon', 'expired'], true)
            ? (string) $request->query('expiry_status')
            : 'all';
        $paginator = $documents->paginate(
            $company,
            $search,
            $documentTypeId,
            $expiryStatus,
            $this->resolvePerPage($request, default: 12, allowed: [12, 24, 48, 100]),
        );
        $items = $paginator->through(fn (CompanyDocument $document) => $documents->present($document));

        return Inertia::render('organization/company-documents', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'logo_url' => $company->logo ? asset('storage/'.$company->logo) : null],
            'documents' => $items->items(),
            'pagination' => $this->paginationMeta($paginator),
            'filters' => [
                'search' => $search,
                'document_type' => $documentTypeId,
                'expiry_status' => $expiryStatus,
            ],
            'summary' => $documents->summary($company),
            'document_types' => DocumentType::query()->where('is_active', true)->orderBy('title')->get(['id', 'title']),
            'can' => $access->permissions($request->user(), $company),
        ]);
    }

    public function store(
        StoreCompanyDocumentRequest $request,
        Company $company,
        CompanyDocumentAccess $access,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['upload']);
        $validated = $request->validated();
        $documentType = DocumentType::query()->whereKey($validated['document_type_id'])->where('is_active', true)->firstOrFail();

        $storage->create($company, $documentType, $request->file('file'), $validated, $request->user()?->id);

        return back()->with('success', 'Company document uploaded.');
    }

    public function update(
        UpdateCompanyDocumentRequest $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['update']);
        abort_unless((int) $companyDocument->company_id === $company->id, 404);
        $data = $request->validated();

        foreach (['title', 'document_number', 'issue_date', 'expiry_date', 'notes'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $companyDocument->update($data);

        return back()->with('success', 'Company document updated.');
    }

    public function destroy(
        Request $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['delete']);
        abort_unless((int) $companyDocument->company_id === $company->id, 404);

        $storage->delete($companyDocument);

        return back()->with('success', 'Company document deleted.');
    }
}
