<?php

namespace Database\Factories;

use App\Models\NavigationFavorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavigationFavorite>
 */
class NavigationFavoriteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'destination_key' => 'employees',
            'position' => 1,
        ];
    }
}
