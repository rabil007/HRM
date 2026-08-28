<?php

namespace App\Enums;

enum DocumentWorkflowAction: string
{
    case Review = 'review';
    case Approve = 'approve';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Review',
            self::Approve => 'Approve',
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
