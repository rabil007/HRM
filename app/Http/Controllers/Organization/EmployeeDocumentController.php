<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:20480'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $path = $request->file('file')->storePublicly(
            "employee-documents/{$companyId}/{$employee->id}/{$validated['document_type']}",
            ['disk' => 'public'],
        );

        EmployeeDocument::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'type' => 'other',
            'document_type' => $validated['document_type'],
            'title' => $validated['title'] ?? null,
            'file_path' => $path,
            'issue_date' => $validated['issue_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'document_number' => $validated['document_number'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => EmployeeDocument::deriveStatus($validated['expiry_date'] ?? null),
            'uploaded_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Document uploaded.');
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
