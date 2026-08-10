<?php

namespace App\Enums;

enum CrewProjectedManningEventType: string
{
    case Join = 'join';
    case SignOff = 'signoff';

    public function label(): string
    {
        return match ($this) {
            self::Join => 'Join',
            self::SignOff => 'Sign-Off',
        };
    }

    /**
     * Same-day ordering: joins before sign-offs (handover without artificial gap).
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Join => 0,
            self::SignOff => 1,
        };
    }
}
