<?php

namespace App\Http\Requests\Organization\Employee\Concerns;

use App\Models\Employee;
use App\Support\Employees\DraftEmployeeNumber;
use Closure;

trait ValidatesEmployeeNumber
{
    /**
     * Normalize employee_no before validation so whitespace-only and explicit
     * null payloads fail the required rule instead of reaching the database.
     */
    protected function prepareEmployeeNumberForValidation(): void
    {
        if (! $this->exists('employee_no')) {
            return;
        }

        $value = $this->input('employee_no');

        if ($value === null) {
            $this->merge(['employee_no' => '']);

            return;
        }

        if (is_string($value) || is_numeric($value)) {
            $this->merge(['employee_no' => trim((string) $value)]);
        }
    }

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
                    $fail('The employee number field is required.');

                    return;
                }

                if (DraftEmployeeNumber::isDraft($employeeNo)) {
                    $fail('Enter the official employee number. Temporary DRAFT identifiers cannot be used as the final employee number.');

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
