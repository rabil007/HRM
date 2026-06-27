<?php

namespace App\Support\Payroll;

use App\Models\SalaryInput;
use Illuminate\Support\Collection;

final class SalaryInputResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SalaryInput $input): array
    {
        $input->loadMissing('salaryInputType');
        $type = $input->salaryInputType;

        return [
            'id' => $input->id,
            'employee_id' => $input->employee_id,
            'period_id' => $input->period_id,
            'salary_input_type_id' => $input->salary_input_type_id,
            'type' => $type?->code,
            'type_label' => $type?->name,
            'is_addition' => (bool) ($type?->is_addition ?? false),
            'amount' => number_format((float) $input->amount, 2, '.', ''),
            'notes' => $input->notes,
        ];
    }

    /**
     * @param  Collection<int, SalaryInput>  $inputs
     * @return array<int, array<string, mixed>>
     */
    public static function groupByEmployee(Collection $inputs): array
    {
        return $inputs
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => $group
                ->sortBy('id')
                ->map(fn (SalaryInput $input) => self::toArray($input))
                ->values()
                ->all())
            ->all();
    }
}
