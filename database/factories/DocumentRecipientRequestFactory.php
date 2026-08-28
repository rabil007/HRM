<?php

namespace Database\Factories;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Models\Company;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRecipientRequest>
 */
class DocumentRecipientRequestFactory extends Factory
{
    protected $model = DocumentRecipientRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rawToken = DocumentRecipientRequestToken::generate();

        return [
            'company_id' => Company::factory(),
            'document_instance_id' => DocumentInstance::factory(),
            'source_document_instance_version_id' => DocumentInstanceVersion::factory(),
            'action' => DocumentRecipientAction::Acknowledge,
            'recipient_type' => DocumentRecipientType::SubjectEmployee,
            'employee_id' => Employee::factory(),
            'recipient_name_snapshot' => fake()->name(),
            'status' => DocumentRecipientRequestStatus::AwaitingAction,
            'token_hash' => DocumentRecipientRequestToken::hash($rawToken),
            'expires_at' => now()->addDays(14),
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'source_checksum_sha256' => hash('sha256', 'test'),
        ];
    }
}
