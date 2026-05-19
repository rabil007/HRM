<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentBulkDeleteController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer'],
        ]);

        $documents = EmployeeDocument::query()
            ->whereIn('id', $request->input('ids'))
            ->where('company_id', $companyId)
            ->get(['id', 'file_path']);

        foreach ($documents as $document) {
            if (! str_starts_with((string) $document->file_path, 'http')) {
                Storage::disk('public')->delete((string) $document->file_path);
            }
        }

        $count = $documents->count();

        EmployeeDocument::query()
            ->whereIn('id', $documents->pluck('id'))
            ->delete();

        return back()->with('success', "{$count} document(s) deleted.");
    }
}
