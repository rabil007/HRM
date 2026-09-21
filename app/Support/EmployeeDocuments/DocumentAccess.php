<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

class DocumentAccess
{
    public static function assertEmployeeInCompany(
        Employee $employee,
        int $companyId,
        int $status = 403,
        ?User $user = null,
        bool $allowSelf = false,
    ): void {
        abort_unless((int) $employee->company_id === $companyId, $status);

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: $allowSelf), 404);
        }
    }

    public static function assertDocumentBelongsToEmployee(
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        int $status = 403,
        ?User $user = null,
        bool $allowSelf = false,
    ): void {
        abort_unless(
            (int) $employee->company_id === $companyId && (int) $document->employee_id === (int) $employee->id,
            $status,
        );

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: $allowSelf), 404);
        }
    }

    public static function assertDocumentInCompany(
        EmployeeDocument $document,
        int $companyId,
        ?User $user = null,
        bool $allowSelf = false,
    ): void {
        abort_unless((int) $document->company_id === $companyId, 404);

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            $employee = ($document->relationLoaded('employee') && $document->employee !== null)
                ? $document->employee
                : Employee::withTrashed()
                    ->where('company_id', $companyId)
                    ->find($document->employee_id);

            abort_if($employee === null, 404);
            abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: $allowSelf), 404);
        }
    }

    public static function assertVersionBelongsToDocument(
        EmployeeDocument $document,
        EmployeeDocumentVersion $version,
        int $companyId,
    ): void {
        abort_unless(
            (int) $document->company_id === $companyId
            && (int) $version->company_id === $companyId
            && (int) $version->employee_document_id === $document->id,
            404,
        );
    }
}
