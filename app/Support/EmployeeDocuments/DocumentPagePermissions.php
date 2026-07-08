<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\EmailTemplateCategory;
use App\Enums\WhatsAppTemplateCategory;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;

class DocumentPagePermissions
{
    /**
     * @return array{
     *     download: bool,
     *     share: bool,
     *     upload: bool,
     *     delete: bool,
     *     whatsapp_template: bool,
     *     whatsapp_templates: list<array<string, mixed>>,
     *     email_templates: list<array<string, mixed>>
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
                    'meta_language' => $template->meta_language,
                    'category' => $template->category->value,
                    'category_label' => $template->category->label(),
                    'body_preview' => $template->body_preview,
                    'is_default' => $template->is_default,
                ])
                ->values()
                ->all()
            : [];

        $emailTemplates = EmailTemplate::query()
            ->enabled()
            ->forCategory(EmailTemplateCategory::Document)
            ->whereNotIn('slug', BulkDocumentTypeRegistry::emailTemplateSlugs())
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (EmailTemplate $template) => [
                'slug' => $template->slug,
                'label' => $template->label,
                'to_preset' => $template->to_preset,
                'cc_preset' => $template->cc_preset,
                'subject' => $template->subject,
                'body_html' => $template->body_html,
                'is_default' => $template->is_default,
            ])
            ->values()
            ->all();

        return [
            'download' => $user?->can('documents.download') ?? false,
            'share' => $user?->can('documents.share') ?? false,
            'upload' => $user?->can('documents.upload') ?? false,
            'delete' => $user?->can('documents.delete') ?? false,
            'whatsapp_template' => ($user?->can('documents.share') ?? false)
                && $canView
                && $whatsappConfigured
                && $templates !== [],
            'whatsapp_templates' => $templates,
            'email_templates' => $emailTemplates,
        ];
    }
}
