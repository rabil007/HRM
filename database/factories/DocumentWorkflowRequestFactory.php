<?php

namespace Database\Factories;

use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\Company;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentWorkflowRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentWorkflowRequest>
 */
class DocumentWorkflowRequestFactory extends Factory
{
    protected $model = DocumentWorkflowRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_instance_id' => DocumentInstance::factory(),
            'document_instance_version_id' => DocumentInstanceVersion::factory(),
            'status' => DocumentWorkflowRequestStatus::Pending,
            'requested_by' => User::factory(),
            'requester_name_snapshot' => fake()->name(),
            'requested_at' => now(),
            'completed_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
