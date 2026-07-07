<?php

namespace App\Support\Contracts;

use App\Enums\PayrollCategory;
use App\Models\EmployeeContract;
use Illuminate\Database\Eloquent\Builder;

final class ContractSalaryStructureFilter
{
    public const DAILY = 'daily';

    public const MONTHLY = 'monthly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::DAILY,
            self::MONTHLY,
        ];
    }

    public static function isValid(?string $filter): bool
    {
        return in_array($filter, self::values(), true);
    }

    /**
     * @param  Builder<EmployeeContract>  $query
     */
    public static function apply(Builder $query, string $structure): void
    {
        if (! self::isValid($structure)) {
            return;
        }

        $query->where(function (Builder $structureQuery) use ($structure): void {
            $structureQuery
                ->where('employee_contracts.salary_structure', $structure)
                ->orWhere(function (Builder $fallbackQuery) use ($structure): void {
                    $fallbackQuery
                        ->whereNull('employee_contracts.salary_structure')
                        ->where(
                            'employee_contracts.payroll_category',
                            $structure === self::DAILY
                                ? PayrollCategory::Crew
                                : PayrollCategory::Office,
                        );
                });
        });
    }
}
