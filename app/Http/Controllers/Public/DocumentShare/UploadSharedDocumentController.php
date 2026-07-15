<?php

namespace App\Http\Controllers\Public\DocumentShare;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Support\EmployeeDocuments\DocumentShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadSharedDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        DocumentShareService $shares,
    ): RedirectResponse {
        $share = $shares->findByToken($token);
        abort_if($share === null, 404);
        $shares->assertAccessible($share);
        $shares->assertUnlocked($share);
        abort_unless($share->allowsUpload(), 403);

        $validated = $request->validate([
            'document_type_id' => [
                'nullable',
                'integer',
                Rule::exists('document_types', 'id')->where('is_active', true),
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:20480',
            ],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $documentType = null;

        if (! empty($validated['document_type_id'])) {
            $documentType = DocumentType::query()
                ->whereKey($validated['document_type_id'])
                ->where('is_active', true)
                ->firstOrFail();
        }

        $shares->storeGuestUpload(
            $share,
            $documentType,
            $validated['file'],
            [
                'document_number' => $validated['document_number'] ?? null,
                'issue_date' => $validated['issue_date'] ?? null,
                'expiry_date' => $validated['expiry_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
        );

        return redirect()->to($shares->shareUrl($share))->with('success', 'Document uploaded successfully.');
    }
}
