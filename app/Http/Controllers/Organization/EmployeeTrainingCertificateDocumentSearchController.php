<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\TrainingCertificateDocumentTypeResolver;
use App\Support\EmployeeTrainings\TrainingAccess;
use App\Support\EmployeeTrainings\TrainingCertificateDocumentPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeTrainingCertificateDocumentSearchController extends Controller
{
    public function __invoke(
        Request $request,
        Employee $employee,
        TrainingCertificateDocumentTypeResolver $documentTypeResolver,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        TrainingAccess::assertEmployeeInCompany($employee, $companyId, 403, $request->user());
        TrainingCertificateDocumentPermissions::assertCanUploadOrLink($request->user());
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 403, $request->user());

        $query = trim((string) $request->query('q', ''));

        $trainingCertificateTypeId = $documentTypeResolver->resolve()->id;

        $documents = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('document_type_id', $trainingCertificateTypeId)
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($inner) use ($query): void {
                    $inner->where('title', 'like', '%'.$query.'%')
                        ->orWhere('original_filename', 'like', '%'.$query.'%');
                });
            })
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'title', 'original_filename', 'expiry_date', 'file_path']);

        return response()->json([
            'documents' => $documents->map(fn (EmployeeDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'expiry_date' => $document->expiry_date?->toDateString(),
                'has_file' => $document->file_path !== null && $document->file_path !== '',
            ])->values()->all(),
        ]);
    }
}
