<?php

namespace App\Support\Employees\Actions;

use App\Models\Employee;
use Illuminate\Support\Str;

final class CreateEmployeeFromName
{
    public function handle(string $name, int $companyId, ?int $employeeProfileTemplateId = null): Employee
    {
        $trimmedName = trim($name);

        return Employee::query()->create([
            'company_id' => $companyId,
            'employee_profile_template_id' => $employeeProfileTemplateId,
            'employee_no' => $this->generateDraftEmployeeNumber($companyId),
            'name' => $trimmedName,
            'status' => 'active',
        ]);
    }

    private function generateDraftEmployeeNumber(int $companyId): string
    {
        do {
            $candidate = 'DRAFT-'.Str::upper(Str::random(8));
        } while (
            Employee::query()
                ->where('company_id', $companyId)
                ->where('employee_no', $candidate)
                ->exists()
        );

        return $candidate;
    }
}
