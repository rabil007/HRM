<?php

namespace App\Support\Announcements;

use App\Support\Auth\UserEmailIdentity;

final class MaskAnnouncementContact
{
    public static function email(string $email): string
    {
        return UserEmailIdentity::mask($email);
    }

    public static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return '******';
        }

        $prefixLength = min(3, strlen($digits) - 2);
        $prefix = substr($digits, 0, $prefixLength);
        $suffix = substr($digits, -2);
        $maskedLength = max(4, strlen($digits) - $prefixLength - 2);

        return '+'.$prefix.str_repeat('*', $maskedLength).$suffix;
    }
}
