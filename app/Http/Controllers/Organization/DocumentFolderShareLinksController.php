<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\CreateFolderShareLinksRequest;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use App\Support\EmployeeDocuments\DocumentShareService;
use Illuminate\Http\JsonResponse;

class DocumentFolderShareLinksController extends Controller
{
    public function __invoke(
        CreateFolderShareLinksRequest $request,
        DocumentBulkActionService $bulkActions,
        DocumentShareService $shares,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $employeeIds = array_values(array_map('intval', $request->validated('employee_ids')));
        $employees = $bulkActions->employeesForDownload($employeeIds, $companyId);

        $password = $request->validated('password');
        $expiresAt = $request->validated('expires_at');
        $canDownload = $request->boolean('can_download', true);
        $canUpload = $request->boolean('can_upload', false);
        $createdBy = $request->user()?->id;

        $payload = $employees->map(function ($employee) use (
            $shares,
            $companyId,
            $createdBy,
            $password,
            $expiresAt,
            $canDownload,
            $canUpload,
        ): array {
            $share = $shares->createFolderShare(
                employee: $employee,
                companyId: $companyId,
                createdBy: $createdBy,
                password: $password,
                expiresAt: $expiresAt,
                canDownload: $canDownload,
                canUpload: $canUpload,
            );

            return [
                'employee_id' => $employee->id,
                'name' => (string) $employee->name,
                'share_url' => $shares->shareUrl($share),
            ];
        })->values()->all();

        return response()->json([
            'shares' => $payload,
        ]);
    }
}
