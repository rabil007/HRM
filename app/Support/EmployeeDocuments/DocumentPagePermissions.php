<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\WhatsAppTemplateCategory;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;

class DocumentPagePermissions
{
    /**
     * @return array{
     *     download: bool,
     *     share: bool,
     *     delete: bool,
     *     whatsapp_template: bool,
     *     whatsapp_templates: list<array<string, mixed>>
     * }
     */
    public static function for(?User $user): array
    {
        $canView = $user?->can('documents.view') ?? false;
        $whatsappSettings = WhatsAppSetting::current();
        $whatsappConfigured = $whatsappSettings->isConfigured();

        $templates = $whatsappConfigured
            ? WhatsAppTemplate::query()
                ->enabled()
                ->forCategory(WhatsAppTemplateCategory::Document)
                ->where('header_type', 'document')
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->map(fn (WhatsAppTemplate $template) => [
                    'slug' => $template->slug,
                    'label' => $template->label,
                    'meta_name' => $template->meta_name,
                    'is_default' => $template->is_default,
                ])
                ->values()
                ->all()
            : [];

        return [
            'download' => $user?->can('documents.download') ?? false,
            'share' => $user?->can('documents.share') ?? false,
            'delete' => $user?->can('documents.delete') ?? false,
            'whatsapp_template' => $canView && $whatsappConfigured && $templates !== [],
            'whatsapp_templates' => $templates,
        ];
    }
}
