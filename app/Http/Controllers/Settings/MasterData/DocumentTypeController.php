<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreDocumentTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateDocumentTypeRequest;
use App\Models\DocumentType;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DocumentTypeController extends Controller
{
    public function index()
    {
        $documentTypes = DocumentType::query()
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'is_active']);

        return Inertia::render('settings/master-data/document-types', [
            'document_types' => $documentTypes,
        ]);
    }

    public function store(StoreDocumentTypeRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;
        $data['slug'] = Str::slug($data['title']);

        DocumentType::query()->create($data);

        return redirect()->route('settings.master-data.document-types.index')->with('success', 'Document type created successfully.');
    }

    public function update(UpdateDocumentTypeRequest $request, DocumentType $document_type)
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['title']);

        $document_type->update($data);

        return redirect()->route('settings.master-data.document-types.index')->with('success', 'Document type updated successfully.');
    }

    public function destroy(DocumentType $document_type)
    {
        $document_type->delete();

        return redirect()->route('settings.master-data.document-types.index')->with('success', 'Document type deleted successfully.');
    }
}
