<?php

namespace App\Http\Requests\Organization\Announcements\Concerns;

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use App\Support\Announcements\ResolveAnnouncementWhatsAppTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait ValidatesAnnouncementWhatsAppLink
{
    /**
     * Shared WhatsApp link rules. The combined 500-character body constraint applies only
     * when the trusted resolved template uses TitleBodyV2.
     *
     * @return list<ValidationRule|Closure|string>
     */
    protected function whatsappLinkRules(): array
    {
        return [
            'nullable',
            'string',
            'url:http,https',
            'max:2048',
            function (string $attribute, mixed $value, Closure $fail): void {
                $this->validateWhatsAppLinkFitsSelectedProfile($value, $fail);
            },
        ];
    }

    protected function validateWhatsAppLinkFitsSelectedProfile(mixed $value, Closure $fail): void
    {
        $link = is_string($value) ? trim($value) : '';

        if ($link === '') {
            return;
        }

        $rawTemplateId = $this->input('whatsapp_template_id');
        $templateId = is_numeric($rawTemplateId) ? (int) $rawTemplateId : null;

        $resolver = app(ResolveAnnouncementWhatsAppTemplate::class);
        $template = $resolver->handle(null, $templateId);

        // Null selection resolves to LegacyV1. Invalid/disabled IDs are rejected by exists rules.
        if ($template === null) {
            return;
        }

        $profile = $resolver->profileFor($template);

        if ($profile !== AnnouncementWhatsAppPayloadProfile::TitleBodyV2) {
            return;
        }

        if (! AnnouncementWhatsAppMessage::optionalLinkFits($link)) {
            $fail('The WhatsApp link is too long to fit in the WhatsApp message body.');
        }
    }
}
