<?php

namespace Database\Factories;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentLifecycleAutomation>
 */
class DocumentLifecycleAutomationFactory extends Factory
{
    protected $model = DocumentLifecycleAutomation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_workflow_preset_id' => null,
            'document_signing_preset_id' => null,
            'document_workflow_request_id' => null,
            'document_signing_flow_id' => null,
            'policy_snapshot' => [
                'schema_version' => DocumentLifecycleAutomationPolicy::SCHEMA_VERSION,
                'workflow_preset_id' => null,
                'workflow_preset_name' => null,
                'signing_preset_id' => null,
                'signing_preset_name' => null,
            ],
            'status' => DocumentLifecycleAutomationStatus::Pending,
            'stage' => null,
            'blocked_code' => null,
            'blocked_message' => null,
            'initiated_by' => null,
            'started_at' => null,
            'blocked_at' => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentLifecycleAutomationStatus::Pending,
            'stage' => null,
        ]);
    }

    public function activeReview(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Review,
            'started_at' => now(),
        ]);
    }

    public function activeSigning(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Signing,
            'started_at' => now(),
        ]);
    }

    public function blocked(
        string $code = DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
        string $message = 'Blocked',
    ): static {
        return $this->state(fn (): array => [
            'status' => DocumentLifecycleAutomationStatus::Blocked,
            'blocked_code' => $code,
            'blocked_message' => $message,
            'blocked_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentLifecycleAutomationStatus::Completed,
            'stage' => DocumentLifecycleAutomationStage::Done,
            'completed_at' => now(),
        ]);
    }

    public function stopped(string $code = DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_CANCELLED): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentLifecycleAutomationStatus::Stopped,
            'blocked_code' => $code,
            'completed_at' => now(),
        ]);
    }
}
