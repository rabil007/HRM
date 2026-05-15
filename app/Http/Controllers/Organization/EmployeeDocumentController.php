<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmployeeDocumentController extends Controller
{
    public function store(Request $request, Employee $employee, StoresEmployeeDocument $store): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'title' => ['nullable', 'string', 'max:200'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:20480'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $documentType = DocumentType::query()
            ->whereKey($validated['document_type_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $store->create($employee, $documentType, $request->file('file'), $validated, $companyId, $request->user()?->id);

        return back()->with('success', 'Document uploaded.');
    }

    public function bulkStore(Request $request, Employee $employee, StoresEmployeeDocument $store): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:20'],
            'documents.*.document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'documents.*.title' => ['nullable', 'string', 'max:200'],
            'documents.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:20480'],
            'documents.*.issue_date' => ['nullable', 'date'],
            'documents.*.expiry_date' => ['nullable', 'date'],
            'documents.*.document_number' => ['nullable', 'string', 'max:120'],
            'documents.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ids = collect($validated['documents'])->pluck('document_type_id')->unique()->all();
        $types = DocumentType::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($validated['documents'] as $index => $documentData) {
            $documentType = $types->get($documentData['document_type_id']);

            abort_unless($documentType instanceof DocumentType, 422);

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

    public function update(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId && $document->employee_id === $employee->id, 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            ...$validated,
            'status' => EmployeeDocument::deriveStatus($validated['expiry_date'] ?? $document->expiry_date?->toDateString()),
        ]);

        return back()->with('success', 'Document updated.');
    }

    public function replace(Request $request, Employee $employee, EmployeeDocument $document, StoresEmployeeDocument $store): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId && $document->employee_id === $employee->id, 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:20480'],
        ]);

        $store->replace($document, $request->file('file'), $companyId, $employee->id, $request->user()?->id);

        return back()->with('success', 'Document file replaced.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId && $document->employee_id === $employee->id, 403);

        if (! str_starts_with($document->file_path, 'http')) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return back()->with('success', 'Document deleted.');
    }
}
