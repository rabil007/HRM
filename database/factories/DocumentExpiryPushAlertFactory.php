<?php

namespace Database\Factories;

use App\Enums\DocumentExpiryPushAlertStatus;
use App\Models\DocumentExpiryPushAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentExpiryPushAlert>
 */
class DocumentExpiryPushAlertFactory extends Factory
{
    protected $model = DocumentExpiryPushAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'employee_document_id' => 1,
            'user_id' => 1,
            'expiry_date_at_alert_time' => now()->toDateString(),
            'status' => DocumentExpiryPushAlertStatus::Queued,
            'queued_at' => now(),
            'sent_at' => null,
            'failed_at' => null,
            'failure_category' => null,
        ];
    }
}
