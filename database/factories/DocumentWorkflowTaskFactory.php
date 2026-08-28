<?php

namespace Database\Factories;

use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\Company;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentWorkflowTask>
 */
class DocumentWorkflowTaskFactory extends Factory
{
    protected $model = DocumentWorkflowTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_workflow_stage_id' => DocumentWorkflowStage::factory(),
            'assignee_user_id' => User::factory(),
            'assignee_name_snapshot' => fake()->name(),
            'status' => DocumentWorkflowTaskStatus::Pending,
            'decided_by' => null,
            'decision_actor_name_snapshot' => null,
            'decided_at' => null,
            'decision_notes' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
