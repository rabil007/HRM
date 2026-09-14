<?php

namespace App\Support\Email;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Schema;

final class SeedBuiltInEmailTemplate
{
    public static function handle(string $slug): EmailTemplate
    {
        $definition = BuiltInEmailTemplates::definition($slug);
        $existing = EmailTemplate::withTrashed()->where('slug', $slug)->first();

        if ($existing === null) {
            return self::create($slug, $definition);
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        if (BuiltInEmailTemplates::matchesLegacyDefault($existing)) {
            $updates = [
                'subject' => $definition['subject'],
                'body_html' => $definition['body_html'],
            ];

            if (in_array($existing->label, $definition['legacy_labels'], true)) {
                $updates['label'] = $definition['label'];
            }

            $existing->forceFill($updates)->save();
        }

        return $existing->fresh() ?? $existing;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function create(string $slug, array $definition): EmailTemplate
    {
        $payload = [
            'slug' => $slug,
            'label' => $definition['label'],
            'category' => $definition['category'],
            'subject' => $definition['subject'],
            'body_html' => $definition['body_html'],
            'is_default' => false,
            'enabled' => $definition['enabled'],
            'sort_order' => $definition['sort_order'],
        ];

        foreach (['to_preset', 'cc_preset', 'dispatch_at', 'include_company_footer'] as $column) {
            if (Schema::hasColumn((new EmailTemplate)->getTable(), $column)) {
                $payload[$column] = $definition[$column];
            }
        }

        $template = EmailTemplate::query()->create($payload);

        if (
            $definition['mark_default_if_none']
            && ! EmailTemplate::query()
                ->where('category', $definition['category'])
                ->where('is_default', true)
                ->whereKeyNot($template->id)
                ->exists()
        ) {
            $template->markAsDefaultForCategory();
        }

        return $template->fresh() ?? $template;
    }
}
