<?php

namespace Database\Factories;

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplateLayoutValidationRun>
 */
class DocumentTemplateLayoutValidationRunFactory extends Factory
{
    protected $model = DocumentTemplateLayoutValidationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_generation_template_id' => DocumentGenerationTemplate::factory(),
            'company_id' => fn (array $attributes) => DocumentGenerationTemplate::query()
                ->whereKey($attributes['document_generation_template_id'])
                ->value('company_id'),
            'document_generation_template_version_id' => fn (array $attributes) => DocumentGenerationTemplateVersion::factory()->create([
                'company_id' => $attributes['company_id'],
                'document_generation_template_id' => $attributes['document_generation_template_id'],
            ])->id,
            'requested_by' => null,
            'mode' => 'sample',
            'employee_id' => null,
            'authoritative' => true,
            'fingerprint' => str_repeat('a', 64),
            'status' => DocumentTemplateLayoutValidationRunStatus::Queued,
            'issues' => null,
            'effective_font_sizes' => null,
            'placement_config' => null,
            'validated_with' => ['mode' => 'sample'],
            'reference' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @param  array{company: mixed, template: DocumentGenerationTemplate, version: DocumentGenerationTemplateVersion}  $context
     */
    public function forDraft(array $context): static
    {
        return $this->state(fn () => [
            'company_id' => $context['company']->id,
            'document_generation_template_id' => $context['template']->id,
            'document_generation_template_version_id' => $context['version']->id,
        ]);
    }

    public function valid(): static
    {
        return $this->state(fn () => [
            'status' => DocumentTemplateLayoutValidationRunStatus::Valid,
            'finished_at' => now(),
            'issues' => [],
            'effective_font_sizes' => [],
        ]);
    }
}
