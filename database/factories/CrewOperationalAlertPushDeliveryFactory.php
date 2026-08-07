<?php

namespace Database\Factories;

use App\Enums\CrewOperationalAlertPushDeliveryStatus;
use App\Models\Company;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertPushDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewOperationalAlertPushDelivery>
 */
class CrewOperationalAlertPushDeliveryFactory extends Factory
{
    protected $model = CrewOperationalAlertPushDelivery::class;

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
            'status' => CrewOperationalAlertPushDeliveryStatus::Queued,
            'queued_at' => now(),
            'sent_at' => null,
            'failed_at' => null,
            'failure_category' => null,
        ];
    }
}
