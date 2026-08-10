<?php

namespace App\Enums;

enum PayrollWorkAllocationStatus: string
{
    case Reserved = 'reserved';
    case Approved = 'approved';
    case Paid = 'paid';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserved',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Reversed => 'Reversed',
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Reserved, self::Approved, self::Paid => true,
            self::Reversed => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Reserved->value,
            self::Approved->value,
            self::Paid->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
