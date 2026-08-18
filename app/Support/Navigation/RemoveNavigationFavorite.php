<?php

namespace App\Support\Navigation;

use App\Models\User;

final class RemoveNavigationFavorite
{
    public function handle(User $user, string $key): void
    {
        $user->navigationFavorites()
            ->where('destination_key', $key)
            ->delete();
    }
}
