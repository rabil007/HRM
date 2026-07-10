<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkStoreEmployeeDocumentRequest;
use App\Http\Requests\Organization\EmployeeDocument\ReplaceEmployeeDocumentRequest;
use App\Http\Requests\Organization\EmployeeDocument\StoreEmployeeDocumentRequest;
use App\Http\Requests\Organization\EmployeeDocument\UpdateEmployeeDocumentRequest;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentDeletionService;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeDocumentController extends Controller
{
    public function store(
        StoreEmployeeDocumentRequest $request,
        Employee $employee,
        StoresEmployeeDocument $store,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId);

        $validated = $request->validated();

        $documentType = DocumentType::query()
            ->whereKey($validated['document_type_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $store->create($employee, $documentType, $request->file('file'), $validated, $companyId, $request->user()?->id);

        return back()->with('success', 'Document uploaded.');
    }

    public function bulkStore(
        BulkStoreEmployeeDocumentRequest $request,
        Employee $employee,
        StoresEmployeeDocument $store,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId);

        $validated = $request->validated();

        $ids = collect($validated['documents'])
            ->pluck('document_type_id')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $types = DocumentType::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($validated['documents'] as $index => $documentData) {
            $documentTypeId = (int) ($documentData['document_type_id'] ?? 0);

            if ($documentTypeId <= 0) {
                throw ValidationException::withMessages([
                    "documents.{$index}.document_type_id" => 'Document type is required.',
                ]);
            }

            $documentType = $types->get($documentTypeId);

            if (! $documentType instanceof DocumentType) {
                throw ValidationException::withMessages([
                    "documents.{$index}.document_type_id" => 'Selected document type is invalid.',
                ]);
            }

            $store->create(
                $employee,
                $documentType,
                $request->file("documents.{$index}.file"),
                $documentData,
                $companyId,
                $request->user()?->id,
            );
        }

        return back()->with('success', 'Documents uploaded.');
    }

    public function update(
        UpdateEmployeeDocumentRequest $request,
        Employee $employee,
        EmployeeDocument $document,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId);

        $validated = $request->validated();

        $expiryDate = EmployeeProfileTemplateRequestRules::hasValidated($validated, 'expiry_date')
            ? ($validated['expiry_date'] ?? null)
            : $document->expiry_date?->toDateString();

        $updates = [
            'title' => EmployeeProfileTemplateRequestRules::persistedValue($validated, 'title', $document->title),
            'document_number' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'document_number')
                ? ($validated['document_number'] ?? null)
                : $document->document_number,
            'issue_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'issue_date')
                ? ($validated['issue_date'] ?? null)
                : $document->issue_date?->toDateString(),
            'expiry_date' => $expiryDate,
            'notes' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'notes')
                ? ($validated['notes'] ?? null)
                : $document->notes,
            'status' => DocumentExpiry::persistedStatus($expiryDate),
        ];

        if (EmployeeProfileTemplateRequestRules::hasValidated($validated, 'document_type_id')) {
            $documentType = DocumentType::query()
                ->whereKey($validated['document_type_id'])
                ->where('is_active', true)
                ->first();

            if (! $documentType instanceof DocumentType) {
                throw ValidationException::withMessages([
                    'document_type_id' => 'The selected document type is invalid.',
                ]);
            }

            $updates['document_type_id'] = $documentType->id;
            $updates['document_type'] = (string) $documentType->id;
        }

        $document->update($updates);

        return back()->with('success', 'Document updated.');
    }

    public function replace(
        ReplaceEmployeeDocumentRequest $request,
        Employee $employee,
        EmployeeDocument $document,
        StoresEmployeeDocument $store,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId);

        $store->replace(
            $document,
            $request->file('file'),
            $companyId,
            $employee->id,
            $request->user()?->id,
            $request->validated()
        );

        return back()->with('success', 'Document file replaced.');
    }

    public function destroy(
        Request $request,
        Employee $employee,
        EmployeeDocument $document,
        DocumentDeletionService $deletion,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId);

        $deletion->delete($document);

        return back()->with('success', 'Document deleted.');
    }

    public function versions(Request $request, Employee $employee, EmployeeDocument $document): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId);

        $document->load(['versions.replacer:id,name']);

        return response()->json([
            'versions' => $document->versions->map(fn ($version) => [
                'id' => $version->id,
                'version' => $version->version,
                'file_url' => $version->file_url,
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'size_bytes' => $version->size_bytes,
                'replaced_by' => $version->replacer?->name,
                'created_at' => $version->created_at?->toDateTimeString(),
            ])->values()->all(),
        ]);
    }
}
