<?php

namespace Database\Factories;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Models\Company;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewOperationalAlertEmailDelivery>
 */
class CrewOperationalAlertEmailDeliveryFactory extends Factory
{
    protected $model = CrewOperationalAlertEmailDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'crew_operational_alert_id' => CrewOperationalAlert::factory(),
            'user_id' => User::factory(),
            'notification_version' => 1,
            'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
            'queued_at' => now(),
            'sent_at' => null,
            'failed_at' => null,
            'failure_category' => null,
            'attempt_count' => 0,
            'last_attempt_at' => null,
        ];
    }
}
