<?php

namespace App\Enums\Recruitment;

enum CandidateJoiningReadinessStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ready => 'Ready',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Ready => 'success',
        };
    }
}
