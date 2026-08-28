<?php

namespace Database\Factories;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Models\Company;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowPresetStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentWorkflowPresetStage>
 */
class DocumentWorkflowPresetStageFactory extends Factory
{
    protected $model = DocumentWorkflowPresetStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_workflow_preset_id' => DocumentWorkflowPreset::factory(),
            'sequence' => 1,
            'action' => DocumentWorkflowAction::Approve,
            'completion_rule' => DocumentWorkflowCompletionRule::Any,
        ];
    }

    public function forPreset(DocumentWorkflowPreset $preset): static
    {
        return $this->state(fn (): array => [
            'company_id' => $preset->company_id,
            'document_workflow_preset_id' => $preset->id,
        ]);
    }
}
