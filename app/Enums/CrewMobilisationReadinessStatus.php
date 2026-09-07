<?php

namespace App\Enums;

enum CrewMobilisationReadinessStatus: string
{
    case Ready = 'ready';
    case Attention = 'attention';
    case NotReady = 'not_ready';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Attention => 'Attention',
            self::NotReady => 'Not Ready',
        };
    }
}
