<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentWorkflowRequest;
use App\Support\Documents\DocumentInstanceStorage;
use App\Support\Documents\Workflow\DocumentWorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentWorkflowVersionPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentWorkflowRequest $workflowRequest,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($request->user()?->can('documents.requests.view'), 403);
        DocumentWorkflowAccess::assertRequestInCompany($workflowRequest, $companyId);

        $version = DocumentInstanceVersion::query()
            ->whereKey($workflowRequest->document_instance_version_id)
            ->where('company_id', $companyId)
            ->where('document_instance_id', $workflowRequest->document_instance_id)
            ->firstOrFail();

        $path = (string) $version->file_path;
        abort_unless(DocumentInstanceStorage::exists($path, $companyId), 404);

        $filename = $version->original_filename ?: 'document.pdf';

        return Storage::disk(DocumentInstanceStorage::DISK)->response(
            $path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            ],
        );
    }
}
