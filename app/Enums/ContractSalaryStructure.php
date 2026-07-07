<?php

namespace App\Enums;

enum ContractSalaryStructure: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function defaultFor(PayrollCategory $category): self
    {
        return match ($category) {
            PayrollCategory::Crew => self::Daily,
            PayrollCategory::Office => self::Monthly,
        };
    }
}
