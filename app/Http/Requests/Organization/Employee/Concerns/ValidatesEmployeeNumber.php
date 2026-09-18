<?php

namespace App\Http\Requests\Organization\Employee\Concerns;

use App\Models\Employee;
use Closure;

trait ValidatesEmployeeNumber
{
    /**
     * @return list<mixed>
     */
    protected function employeeNumberRules(int $companyId, ?int $ignoreEmployeeId = null): array
    {
        return [
            'required',
            'string',
            'max:50',
            function (string $attribute, mixed $value, Closure $fail) use ($companyId, $ignoreEmployeeId): void {
                $employeeNo = trim((string) $value);

                if ($employeeNo === '') {
                    return;
                }

                $existing = Employee::withTrashed()
                    ->where('company_id', $companyId)
                    ->where('employee_no', $employeeNo)
                    ->when(
                        $ignoreEmployeeId !== null,
                        fn ($query) => $query->where('id', '!=', $ignoreEmployeeId),
                    )
                    ->first();

                if ($existing === null) {
                    return;
                }

                if ($existing->trashed()) {
                    $fail("Employee No. {$employeeNo} belongs to a deleted employee. Restore the existing employee from Employees > Deleted instead of creating a duplicate.");

                    return;
                }

                $fail('This employee number is already used in your company. Choose a different number.');
            },
        ];
    }
}
