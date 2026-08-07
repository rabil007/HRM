<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewOperationalAlertRecipient>
 */
class CrewOperationalAlertRecipientFactory extends Factory
{
    protected $model = CrewOperationalAlertRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'crew_operational_alert_id' => CrewOperationalAlert::factory(),
            'user_id' => User::factory(),
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => [
            'read_at' => now(),
        ]);
    }
}
