<?php

namespace App\Support\Users;

use Illuminate\Support\Facades\Storage;

final class UserAvatar
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::url($path);
    }
}
