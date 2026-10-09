<?php

namespace App\Enums;

enum CrewScheduledMovementStatus: string
{
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Executed = 'executed';
    case NeedsAttention = 'needs_attention';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Processing => 'Processing',
            self::Executed => 'Executed',
            self::NeedsAttention => 'Needs Attention',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isUnresolved(): bool
    {
        return match ($this) {
            self::Scheduled, self::Processing, self::NeedsAttention => true,
            self::Executed, self::Cancelled => false,
        };
    }

    /**
     * Statuses that block a second active schedule on the same assignment.
     *
     * @return list<string>
     */
    public static function unresolvedValues(): array
    {
        return [
            self::Scheduled->value,
            self::Processing->value,
            self::NeedsAttention->value,
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
