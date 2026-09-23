<?php

namespace App\Enums\Recruitment;

enum RequirementDeadlineHealth: string
{
    case OnTrack = 'on_track';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'On Track',
            self::DueSoon => 'Due Soon',
            self::Overdue => 'Overdue',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::OnTrack => 'success',
            self::DueSoon => 'warning',
            self::Overdue => 'destructive',
        };
    }
}
