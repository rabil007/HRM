<?php

namespace App\Support\EmployeeDocuments;

use App\Models\EmployeeDocument;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LatestEmployeeDocumentQuery
{
    /**
     * Latest current EmployeeDocument per employee + document type.
     *
     * Canonical rule: created_at DESC, then id DESC (matches EmployeeDocument::latestUpload()).
     */
    public function forCompany(int $companyId, ?int $employeeId = null): Builder
    {
        $table = (new EmployeeDocument)->getTable();

        $ranked = EmployeeDocument::query()
            ->forCompany($companyId)
            ->whereNotNull("{$table}.document_type_id")
            ->when($employeeId !== null, fn ($query) => $query->where("{$table}.employee_id", $employeeId))
            ->select([
                "{$table}.id",
                "{$table}.employee_id",
                "{$table}.document_type_id",
            ])
            ->selectRaw(
                "ROW_NUMBER() OVER (PARTITION BY {$table}.employee_id, {$table}.document_type_id ORDER BY {$table}.created_at DESC, {$table}.id DESC) as latest_row_number",
            );

        return DB::query()
            ->fromSub($ranked, 'latest_employee_documents')
            ->where('latest_row_number', 1)
            ->select([
                'latest_employee_documents.id',
                'latest_employee_documents.employee_id',
                'latest_employee_documents.document_type_id',
            ]);
    }

    public function idsForCompany(int $companyId, ?int $employeeId = null): Builder
    {
        return $this->forCompany($companyId, $employeeId)->select('latest_employee_documents.id');
    }
}
