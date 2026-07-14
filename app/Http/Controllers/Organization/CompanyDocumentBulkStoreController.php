<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompanyDocument\BulkStoreCompanyDocumentsRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use App\Support\CompanyDocuments\CompanyDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class CompanyDocumentBulkStoreController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        BulkStoreCompanyDocumentsRequest $request,
        Company $company,
        CompanyDocumentAccess $access,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['upload']);
        $validated = $request->validated();
        $documentTypes = DocumentType::query()
            ->whereIn('id', collect($validated['documents'])->pluck('document_type_id')->unique())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $documents = collect($validated['documents'])->map(function (array $data, int $index) use ($documentTypes, $request): array {
            $documentType = $documentTypes->get((int) $data['document_type_id']);

            if (! $documentType instanceof DocumentType) {
                throw ValidationException::withMessages([
                    "documents.{$index}.document_type_id" => 'The selected document type is no longer active.',
                ]);
            }

            return [
                'document_type' => $documentType,
                'file' => $request->file("documents.{$index}.file"),
                'data' => $data,
            ];
        })->all();

        $storage->createMany($company, $documents, $request->user()?->id);

        return back()->with('success', count($documents).' company documents uploaded.');
    }
}
