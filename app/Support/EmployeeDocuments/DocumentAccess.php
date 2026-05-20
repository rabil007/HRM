<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;

class DocumentAccess
{
    public static function assertEmployeeInCompany(Employee $employee, int $companyId, int $status = 403): void
    {
        abort_unless($employee->company_id === $companyId, $status);
    }

    public static function assertDocumentBelongsToEmployee(
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        int $status = 403,
    ): void {
        abort_unless(
            $employee->company_id === $companyId && $document->employee_id === $employee->id,
            $status,
        );
    }

    public static function assertDocumentInCompany(EmployeeDocument $document, int $companyId): void
    {
        abort_unless($document->company_id === $companyId, 404);
    }
}
