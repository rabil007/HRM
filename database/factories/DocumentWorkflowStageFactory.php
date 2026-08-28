<?php

namespace Database\Factories;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowStageStatus;
use App\Models\Company;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentWorkflowStage>
 */
class DocumentWorkflowStageFactory extends Factory
{
    protected $model = DocumentWorkflowStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_workflow_request_id' => DocumentWorkflowRequest::factory(),
            'sequence' => 1,
            'action' => DocumentWorkflowAction::Review,
            'completion_rule' => DocumentWorkflowCompletionRule::All,
            'status' => DocumentWorkflowStageStatus::Active,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
