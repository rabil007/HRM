<?php

namespace Database\Factories;

use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppTemplate>
 */
class WhatsAppTemplateFactory extends Factory
{
    protected $model = WhatsAppTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->words(2, true), '_');

        return [
            'slug' => $slug,
            'label' => Str::headline(str_replace('_', ' ', $slug)),
            'category' => WhatsAppTemplateCategory::Document,
            'meta_name' => $slug,
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Document,
            'body_preview' => 'Hello {{name}}, please find the attached document.',
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }

    public function documentDefault(): static
    {
        return $this->state(fn () => [
            'slug' => 'document_delivery',
            'label' => 'Document delivery',
            'category' => WhatsAppTemplateCategory::Document,
            'meta_name' => 'document_delivery',
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Document,
            'body_preview' => 'Hello {{name}}, Please find the attached document from Overseas Marine Services. Thank you.',
            'is_default' => true,
        ]);
    }
}
