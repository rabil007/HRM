<?php

namespace App\Enums;

enum SalaryAdjustmentType: string
{
    case Bonus = 'bonus';
    case Commission = 'commission';
    case Deduction = 'deduction';
    case Loan = 'loan';
    case Advance = 'advance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bonus => 'Bonus',
            self::Commission => 'Commission',
            self::Deduction => 'Deduction',
            self::Loan => 'Loan',
            self::Advance => 'Advance',
            self::Other => 'Other',
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
