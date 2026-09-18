<?php

namespace App\Support\Employees\Resources;

use App\Models\Employee;

final class EmployeeDeletedListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'branch' => $employee->branch_id ? [
                'id' => $employee->branch_id,
                'name' => $employee->branch?->name,
            ] : null,
            'department' => $employee->department_id ? [
                'id' => $employee->department_id,
                'name' => $employee->department?->name,
            ] : null,
            'position' => $employee->position_id ? [
                'id' => $employee->position_id,
                'title' => $employee->position?->title,
            ] : null,
            'work_email' => $employee->work_email,
            'phone' => $employee->phone,
            'status' => $employee->status,
            'deleted_at' => $employee->deleted_at,
        ];
    }
}
