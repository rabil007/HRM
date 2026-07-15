<?php

namespace App\Http\Controllers\Public\DocumentShare;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use App\Support\EmployeeDocuments\DocumentShareService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DownloadSharedDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        int $document,
        DocumentShareService $shares,
        DocumentDownloadService $downloads,
    ): Response {
        $share = $shares->findByToken($token);
        abort_if($share === null, 404);
        $shares->assertAccessible($share);
        $shares->assertUnlocked($share);
        abort_unless($share->can_download, 403);

        $employeeDocument = $shares->findDocumentInShare($share, $document);

        return $downloads->downloadSingleDocument($employeeDocument, (int) $share->company_id);
    }
}
