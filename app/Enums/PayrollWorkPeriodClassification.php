<?php

namespace App\Enums;

enum PayrollWorkPeriodClassification: string
{
    case Current = 'current';
    case Prior = 'prior';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current-period',
            self::Prior => 'Prior-period',
        };
    }
}
