<?php

namespace App\Support\BulkDocuments;

use App\Models\Employee;

final class BulkDocumentRosterEmployeePresenter
{
    /**
     * Shared employee identity for Salary Certificate and Company Template rosters.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     employee_no: string|null,
     *     image: string|null,
     *     department: string|null,
     *     position: string|null,
     *     email: string|null,
     *     status: string
     * }
     */
    public static function identity(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => (string) $employee->name,
            'employee_no' => $employee->employee_no,
            'image' => $employee->image,
            'department' => $employee->department?->name,
            'position' => $employee->position?->title,
            'email' => $employee->work_email ?: $employee->personal_email,
            'status' => (string) $employee->status,
        ];
    }
}
