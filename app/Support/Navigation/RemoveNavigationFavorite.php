<?php

namespace App\Support\Navigation;

use App\Models\User;

final class RemoveNavigationFavorite
{
    public function handle(User $user, string $key): void
    {
        $keys = $key === 'documents.bulk'
            ? ['documents.bulk', 'documents.activity']
            : [$key];

        $user->navigationFavorites()
            ->whereIn('destination_key', $keys)
            ->delete();
    }
}
