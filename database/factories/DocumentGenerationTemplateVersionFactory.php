<?php

namespace Database\Factories;

use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentGenerationTemplateVersion>
 */
class DocumentGenerationTemplateVersionFactory extends Factory
{
    protected $model = DocumentGenerationTemplateVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => DocumentGenerationTemplate::factory(),
            'document_generation_template_id' => function (array $attributes) {
                return $attributes['company_id'];
            },
            'version' => 1,
            'status' => DocumentGenerationTemplateVersionStatus::Draft,
            'content' => "Dear {{employee_name}},\n\nWelcome to {{company_name}}.",
            'source_pdf_path' => null,
            'source_pdf_original_name' => null,
            'source_pdf_size_bytes' => null,
            'source_pdf_page_count' => null,
            'placement_config' => null,
            'published_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => DocumentGenerationTemplateVersionStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function forTemplate(DocumentGenerationTemplate $template): static
    {
        return $this->state(fn () => [
            'company_id' => $template->company_id,
            'document_generation_template_id' => $template->id,
        ]);
    }
}
