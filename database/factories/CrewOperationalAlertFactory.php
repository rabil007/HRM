<?php

namespace Database\Factories;

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Models\Company;
use App\Models\CrewOperationalAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewOperationalAlert>
 */
class CrewOperationalAlertFactory extends Factory
{
    protected $model = CrewOperationalAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'company_id' => Company::factory(),
            'type' => CrewOperationalAlertType::SignoffOverdue,
            'severity' => CrewOperationalAlertSeverity::Critical,
            'status' => CrewOperationalAlertStatus::Active,
            'dedupe_key' => 'signoff_overdue:assignment:'.$this->faker->unique()->numberBetween(1, 999999),
            'title' => 'Sign-off overdue',
            'message' => 'Test operational alert',
            'context' => [],
            'detected_at' => $now,
            'last_detected_at' => $now,
            'resolved_at' => null,
            'notification_version' => 1,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => CrewOperationalAlertStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
