<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BranchDocument\BulkStoreBranchDocumentRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentType;
use App\Support\CompanyDocuments\CompanyDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class BranchDocumentBulkStoreController extends Controller
{
    public function __invoke(
        BulkStoreBranchDocumentRequest $request,
        Branch $branch,
        CompanyDocumentStorage $storage,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

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

        $storage->createMany($company, $documents, $request->user()?->id, $branch->id);

        return back()->with('success', count($documents).' branch documents uploaded.');
    }
}
