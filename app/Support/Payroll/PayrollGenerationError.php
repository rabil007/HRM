<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

final class PayrollGenerationError
{
    /**
     * @return array{
     *     employee_id: int,
     *     employee_name: string,
     *     employee_no: string|null,
     *     message: string,
     *     field: string|null,
     *     field_label: string|null,
     *     employee_url: string
     * }
     */
    public static function forEmployee(Employee $employee, string $message, ?string $field = null): array
    {
        return [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'employee_no' => $employee->employee_no,
            'message' => $message,
            'field' => $field,
            'field_label' => self::fieldLabel($field),
            'employee_url' => route('organization.employees.show', $employee),
        ];
    }

    /**
     * @return array{
     *     employee_id: int,
     *     employee_name: string,
     *     employee_no: string|null,
     *     message: string,
     *     field: string|null,
     *     field_label: string|null,
     *     employee_url: string
     * }
     */
    public static function fromValidationException(Employee $employee, ValidationException $exception): array
    {
        $errors = $exception->errors();
        $field = array_key_first($errors);
        $message = collect($errors)->flatten()->first() ?? 'Calculation failed.';

        return self::forEmployee(
            $employee,
            (string) $message,
            is_string($field) ? $field : null,
        );
    }

    public static function fieldLabel(?string $field): ?string
    {
        return match ($field) {
            'basic_salary' => 'Basic monthly salary',
            'contract' => 'Office contract',
            default => $field !== null
                ? str($field)->replace('_', ' ')->title()->toString()
                : null,
        };
    }
}
