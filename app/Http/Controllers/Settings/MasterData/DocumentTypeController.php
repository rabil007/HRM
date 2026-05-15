<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportDocumentTypesRequest;
use App\Http\Requests\Settings\MasterData\StoreDocumentTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateDocumentTypeRequest;
use App\Models\DocumentType;
use Illuminate\Http\Response;
use Inertia\Inertia;

class DocumentTypeController extends Controller
{
    public function index()
    {
        $documentTypes = DocumentType::query()
            ->orderBy('title')
            ->get(['id', 'title', 'is_active']);

        return Inertia::render('settings/master-data/document-types', [
            'document_types' => $documentTypes,
        ]);
    }

    public function store(StoreDocumentTypeRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        DocumentType::query()->create($data);

        return redirect()->route('settings.master-data.document-types.index')->with('success', 'Document type created successfully.');
    }

    public function update(UpdateDocumentTypeRequest $request, DocumentType $document_type)
    {
        $document_type->update($request->validated());

        return redirect()->route('settings.master-data.document-types.index')->with('success', 'Document type updated successfully.');
    }

    public function destroy(DocumentType $document_type)
    {
        $document_type->delete();

        return redirect()->route('settings.master-data.document-types.index')->with('success', 'Document type deleted successfully.');
    }

    public function importTemplate(): Response
    {
        $csv = "title,is_active\nPassport Copy,yes\nVisa,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="document-types-import-template.csv"',
        ]);
    }

    public function import(ImportDocumentTypesRequest $request)
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.document-types.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.document-types.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['title', 'name', 'document type', 'type'], true)) {
                $map['title'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['title'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.document-types.index')
                ->withErrors(['file' => 'The CSV must include a title column.']);
        }

        $imported = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row[$map['title']] ?? ''));
            if ($title === '') {
                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            DocumentType::query()->updateOrCreate(
                ['title' => $title],
                ['is_active' => $active],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        return redirect()
            ->route('settings.master-data.document-types.index')
            ->with('success', $imported > 0
                ? "Imported {$imported} document type row(s)."
                : 'No rows were imported.');
    }
}
