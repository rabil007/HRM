<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class ExportBulkDocumentSignatureEmployees
{
    /**
     * @param  list<int>  $signatureRequestIds
     * @return Builder<Employee>
     */
    public function query(int $companyId, string $documentTypeKey, array $signatureRequestIds): Builder
    {
        $employeeIds = BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', $documentTypeKey)
            ->whereIn('id', $signatureRequestIds)
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->with([
                'department:id,name',
                'position:id,title',
            ])
            ->orderBy('name')
            ->orderBy('id');
    }
}
