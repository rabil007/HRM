<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeTraining;

final class TrainingEmployeeBrowseQuery
{
    /**
     * @return array{
     *     employee: array{id: int, name: string, employee_no: string},
     *     trainings: list<array<string, mixed>>
     * }
     */
    public function forEmployee(int $companyId, Employee $employee): array
    {
        $trainings = EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['course:id,name', 'country:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeTraining $training) => TrainingListResource::toProfileArray($training))
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
            'trainings' => $trainings,
        ];
    }
}
