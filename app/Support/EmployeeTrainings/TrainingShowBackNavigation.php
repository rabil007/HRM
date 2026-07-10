<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use Illuminate\Http\Request;

class TrainingShowBackNavigation
{
    /**
     * @return array{href: string, label: string}
     */
    public static function resolve(Request $request, Employee $employee): array
    {
        return [
            'href' => route('organization.employees.show', $employee).'#training',
            'label' => 'Back to employee profile',
        ];
    }
}
