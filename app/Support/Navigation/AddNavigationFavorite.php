<?php

namespace App\Support\Navigation;

use App\Models\NavigationFavorite;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AddNavigationFavorite
{
    public function handle(User $user, string $key): NavigationFavorite
    {
        abort_unless(NavigationDestinationCatalog::isAccessibleKey($user, $key), 403);

        $storageKeys = NavigationDestinationCatalog::favoriteStorageKeys($key);

        $existing = $user->navigationFavorites()
            ->whereIn('destination_key', $storageKeys)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            if ($existing->destination_key !== $key) {
                $existing->update(['destination_key' => $key]);
            }

            return $existing;
        }

        $currentCount = $user->navigationFavorites()->count();

        if ($currentCount >= NavigationFavorite::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'key' => 'You can pin at most '.NavigationFavorite::MAX_PER_USER.' destinations.',
            ]);
        }

        $nextPosition = (int) $user->navigationFavorites()->max('position') + 1;

        return $user->navigationFavorites()->create([
            'destination_key' => $key,
            'position' => $nextPosition,
        ]);
    }
}
