<?php

namespace Database\Factories;

use App\Enums\DocumentWorkflowTargetType;
use App\Models\Company;
use App\Models\DocumentWorkflowPresetStage;
use App\Models\DocumentWorkflowPresetTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentWorkflowPresetTarget>
 */
class DocumentWorkflowPresetTargetFactory extends Factory
{
    protected $model = DocumentWorkflowPresetTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_workflow_preset_stage_id' => DocumentWorkflowPresetStage::factory(),
            'target_type' => DocumentWorkflowTargetType::SpecificUser,
            'target_user_id' => User::factory(),
            'target_role_id' => null,
        ];
    }

    public function forStage(DocumentWorkflowPresetStage $stage): static
    {
        return $this->state(fn (): array => [
            'company_id' => $stage->company_id,
            'document_workflow_preset_stage_id' => $stage->id,
        ]);
    }
}
