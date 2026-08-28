<?php

namespace App\Enums;

enum DocumentWorkflowCompletionRule: string
{
    case All = 'all';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Any => 'Any',
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
