<?php

namespace App\Enums;

enum DocumentLifecycleAutomationStage: string
{
    case Review = 'review';
    case Signing = 'signing';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Review & Approval',
            self::Signing => 'Signing',
            self::Done => 'Done',
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
