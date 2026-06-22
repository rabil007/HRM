<?php

namespace App\Enums;

enum SalaryComponentRateType: string
{
    case Monthly = 'monthly';
    case Daily = 'daily';
    case Hourly = 'hourly';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Daily => 'Daily',
            self::Hourly => 'Hourly',
            self::Fixed => 'Fixed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
