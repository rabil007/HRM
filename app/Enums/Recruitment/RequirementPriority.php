<?php

namespace App\Enums\Recruitment;

enum RequirementPriority: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Urgent => 'Urgent',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Normal => 'outline',
            self::Urgent => 'destructive',
        };
    }
}
