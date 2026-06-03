<?php

namespace Database\Factories;

use App\Enums\EmailTemplateCategory;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->words(2, true), '_');

        return [
            'slug' => $slug,
            'label' => Str::headline(str_replace('_', ' ', $slug)),
            'category' => EmailTemplateCategory::General,
            'to_preset' => null,
            'cc_preset' => null,
            'subject' => 'Message from Overseas Marine Services',
            'body_html' => "Hello,\n\nPlease see the details below.\n\nThank you.",
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }

    public function documentDefault(): static
    {
        return $this->state(fn () => [
            'slug' => 'document_share',
            'label' => 'Document share',
            'category' => EmailTemplateCategory::Document,
            'subject' => 'Documents from Overseas Marine Services',
            'body_html' => "Hello,\n\nPlease find the attached employee documents.\n\nThank you.",
            'is_default' => true,
        ]);
    }
}
