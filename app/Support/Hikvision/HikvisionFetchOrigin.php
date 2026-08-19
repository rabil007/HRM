<?php

namespace App\Support\Hikvision;

enum HikvisionFetchOrigin: string
{
    case Manual = 'manual';
    case ScheduledToday = 'scheduled_today';
    case ScheduledReconciliation = 'scheduled_reconciliation';
    case CatchUp = 'catch_up';

    public static function fromValue(string|self|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return match ($value) {
            'scheduled_today' => self::ScheduledToday,
            'scheduled_reconciliation' => self::ScheduledReconciliation,
            'catch_up' => self::CatchUp,
            default => self::Manual,
        };
    }
}
