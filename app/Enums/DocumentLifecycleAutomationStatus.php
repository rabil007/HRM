<?php

namespace App\Enums;

enum DocumentLifecycleAutomationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Blocked => 'Blocked',
            self::Completed => 'Completed',
            self::Stopped => 'Stopped',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Stopped;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
