<?php

namespace Database\Factories;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Models\Company;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRecipientRequestDelivery>
 */
class DocumentRecipientRequestDeliveryFactory extends Factory
{
    protected $model = DocumentRecipientRequestDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_recipient_request_id' => DocumentRecipientRequest::factory(),
            'channel' => DocumentRecipientRequestDeliveryChannel::Email,
            'purpose' => DocumentRecipientRequestDeliveryPurpose::Initial,
            'delivery_sequence' => 1,
            'destination_snapshot' => fake()->safeEmail(),
            'template_slug' => 'document_recipient_action_request',
            'status' => DocumentRecipientRequestDeliveryStatus::Queued,
            'attempt_count' => 0,
        ];
    }
}
