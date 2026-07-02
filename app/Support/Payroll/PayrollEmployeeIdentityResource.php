<?php

namespace App\Support\Payroll;

use App\Models\Employee;

final class PayrollEmployeeIdentityResource
{
    /**
     * @return array<string, mixed>
     */
    public static function forEmployee(?Employee $employee): array
    {
        if ($employee === null) {
            return [
                'id' => 0,
                'name' => '—',
                'employee_no' => null,
                'image' => null,
                'department' => null,
                'position' => null,
            ];
        }

        $employee->loadMissing([
            'department.parent:id,name',
            'position:id,title',
        ]);

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'employee_no' => $employee->employee_no,
            'image' => $employee->image,
            'department' => $employee->department_id ? [
                'id' => $employee->department_id,
                'name' => $employee->department?->name,
                'parent' => $employee->department?->parent_id ? [
                    'id' => $employee->department->parent_id,
                    'name' => $employee->department->parent?->name,
                ] : null,
            ] : null,
            'position' => $employee->position_id ? [
                'id' => $employee->position_id,
                'title' => $employee->position?->title,
            ] : null,
        ];
    }
}
