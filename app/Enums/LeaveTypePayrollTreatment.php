<?php

namespace App\Enums;

enum LeaveTypePayrollTreatment: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid leave',
            self::Unpaid => 'Unpaid leave',
        };
    }

    public function isUnpaid(): bool
    {
        return $this === self::Unpaid;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Legacy unpaid leave codes used before payroll_treatment existed.
     *
     * @return list<string>
     */
    public static function legacyUnpaidCodes(): array
    {
        return ['UL', 'UNPAID', 'LOP'];
    }
}
