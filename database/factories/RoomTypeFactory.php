<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set on RoomTypeFactory.');
            },
            'hotel_id' => static function (array $attributes): int {
                if (isset($attributes['hotel_id'])) {
                    return (int) $attributes['hotel_id'];
                }

                return Hotel::factory()->create([
                    'company_id' => $attributes['company_id'],
                ])->id;
            },
            'name' => 'Room '.Str::uuid()->toString(),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
