<?php

namespace App\Http\Controllers\Public\DocumentShare;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentShareService;
use App\Support\Employees\EmployeeFormOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowDocumentShareController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        DocumentShareService $shares,
    ): Response {
        $share = $shares->findByToken($token);
        abort_if($share === null, 404);
        $shares->assertAccessible($share);

        $unlocked = $shares->isUnlocked($share);

        return Inertia::render('shared/show', [
            'token' => $share->token,
            'scope' => $share->scope->value,
            'employee' => [
                'name' => (string) ($share->employee?->name ?? 'Employee'),
                'employee_no' => $share->employee?->employee_no,
            ],
            'company_name' => (string) ($share->company?->name ?? ''),
            'expires_at' => $share->expires_at->toIso8601String(),
            'requires_password' => $share->hasPassword(),
            'unlocked' => $unlocked,
            'can_download' => $share->can_download,
            'can_upload' => $share->allowsUpload(),
            'unlock_url' => $shares->unlockUrl($share),
            'upload_url' => $share->allowsUpload() ? $shares->uploadUrl($share) : null,
            'documents' => $unlocked ? $shares->guestDocumentPayload($share) : [],
            'document_types' => $unlocked && $share->allowsUpload()
                ? EmployeeFormOptions::documentTypes()
                : [],
        ]);
    }
}
