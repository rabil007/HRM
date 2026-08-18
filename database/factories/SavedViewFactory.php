<?php

namespace Database\Factories;

use App\Enums\SavedViewPage;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<SavedView>
 */
class SavedViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => static function (): int {
                throw new InvalidArgumentException('company_id must be set via create([...])');
            },
            'page_key' => SavedViewPage::Employees,
            'name' => 'Active employees',
            'filters' => ['status' => 'active'],
            'is_default' => false,
        ];
    }
}
