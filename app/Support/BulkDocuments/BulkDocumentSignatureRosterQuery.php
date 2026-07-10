<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class BulkDocumentSignatureRosterQuery
{
    public static function paginate(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        int $perPage,
        int $page,
        ?string $statusFilter = null,
        string $emailFilter = 'all',
    ): LengthAwarePaginator {
        $query = BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', $documentTypeKey)
            ->whereHas('employee', function (Builder $employeeQuery) use ($companyId, $filters, $documentTypeKey, $emailFilter): void {
                EmployeeDirectoryQuery::applyAttributeFilters($employeeQuery, $companyId, $filters);
                BulkDocumentRosterQuery::applyEmailFilter($employeeQuery, $companyId, $documentTypeKey, $emailFilter);
            })
            ->with([
                'employee:id,name,employee_no,image,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'employeeDocument:id,file_path',
                'reviewedBy:id,name',
            ])
            ->latest('signed_at')
            ->latest('id');

        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        return $query->paginate($perPage, ['*'], 'page', $page)->withQueryString()->through(
            fn (BulkDocumentSignatureRequest $request): array => self::mapRequest($request),
        );
    }

    public static function pendingReviewCount(
        int $companyId,
        string $documentTypeKey,
        ?EmployeeDirectoryFilters $filters = null,
        string $emailFilter = 'all',
    ): int {
        $query = BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', $documentTypeKey)
            ->where('status', BulkDocumentSignatureRequestStatus::Submitted);

        if ($filters !== null) {
            $query->whereHas('employee', function (Builder $employeeQuery) use ($companyId, $filters, $documentTypeKey, $emailFilter): void {
                EmployeeDirectoryQuery::applyAttributeFilters($employeeQuery, $companyId, $filters);
                BulkDocumentRosterQuery::applyEmailFilter($employeeQuery, $companyId, $documentTypeKey, $emailFilter);
            });
        }

        return $query->count();
    }

    public static function awaitingSignatureCount(
        int $companyId,
        string $documentTypeKey,
        ?EmployeeDirectoryFilters $filters = null,
        string $emailFilter = 'all',
    ): int {
        $query = BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', $documentTypeKey)
            ->where('status', BulkDocumentSignatureRequestStatus::AwaitingSignature);

        if ($filters !== null) {
            $query->whereHas('employee', function (Builder $employeeQuery) use ($companyId, $filters, $documentTypeKey, $emailFilter): void {
                EmployeeDirectoryQuery::applyAttributeFilters($employeeQuery, $companyId, $filters);
                BulkDocumentRosterQuery::applyEmailFilter($employeeQuery, $companyId, $documentTypeKey, $emailFilter);
            });
        }

        return $query->count();
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, string>
     */
    public static function latestStatusByEmployee(
        int $companyId,
        string $documentTypeKey,
        array $employeeIds,
    ): array {
        if ($employeeIds === []) {
            return [];
        }

        return BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', $documentTypeKey)
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('id')
            ->get(['employee_id', 'status'])
            ->unique('employee_id')
            ->mapWithKeys(fn (BulkDocumentSignatureRequest $request): array => [
                $request->employee_id => $request->status->value,
            ])
            ->all();
    }

    public static function findByToken(string $token): ?BulkDocumentSignatureRequest
    {
        return BulkDocumentSignatureRequest::query()
            ->where('token', $token)
            ->with(['employee:id,name,employee_no,company_id', 'employeeDocument', 'company:id,name'])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapRequest(BulkDocumentSignatureRequest $request): array
    {
        return [
            'id' => $request->id,
            'employee' => [
                'id' => $request->employee?->id,
                'name' => (string) ($request->employee?->name ?? ''),
                'employee_no' => $request->employee?->employee_no,
                'image' => $request->employee?->image,
                'department' => $request->employee?->department?->name,
                'position' => $request->employee?->position?->title,
            ],
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'signed_name' => $request->signed_name,
            'signed_at' => $request->signed_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'reviewed_by' => $request->reviewedBy?->name,
            'rejection_reason' => $request->rejection_reason,
            'unsigned_document_id' => $request->employee_document_id,
            'unsigned_file_path' => $request->employeeDocument?->file_path,
            'signed_pdf_path' => $request->signed_pdf_path,
            'expires_at' => $request->expires_at?->toIso8601String(),
        ];
    }
}
