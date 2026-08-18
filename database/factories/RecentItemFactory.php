<?php

namespace Database\Factories;

use App\Enums\RecentItemType;
use App\Models\RecentItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<RecentItem>
 */
class RecentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => static function (): int {
                throw new InvalidArgumentException('company_id must be set via for() or create([...])');
            },
            'record_type' => RecentItemType::Employee,
            'record_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'last_viewed_at' => now(),
        ];
    }
}
