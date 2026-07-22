<?php

namespace App\Support\Auth;

final class RememberSession
{
    public const SESSION_KEY = 'login.remember';

    public const LIFETIME_MINUTES = 60 * 24 * 30;

    public static function mark(): void
    {
        session([self::SESSION_KEY => true]);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public static function isMarked(): bool
    {
        return (bool) session(self::SESSION_KEY, false);
    }

    public static function extendLifetime(): void
    {
        config(['session.lifetime' => self::LIFETIME_MINUTES]);
    }

    public static function applyLifetime(): void
    {
        if (! self::isMarked()) {
            return;
        }

        self::extendLifetime();
    }
}
