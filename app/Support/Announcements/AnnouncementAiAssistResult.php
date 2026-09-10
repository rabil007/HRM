<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Models\WhatsAppTemplate;

final class AnnouncementAiAssistResult
{
    /**
     * @param  array{
     *     id: int,
     *     label: string,
     *     purpose: string
     * }|null  $suggestedTemplate
     */
    public function __construct(
        public readonly string $title,
        public readonly string $mainBodyHtml,
        public readonly string $whatsappMessage,
        public readonly ?AnnouncementWhatsAppTemplatePurpose $templatePurpose,
        public readonly ?array $suggestedTemplate,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromDecoded(array $decoded, ListAnnouncementWhatsAppTemplates $templates): self
    {
        $title = trim((string) ($decoded['title'] ?? ''));
        $mainBody = trim((string) ($decoded['main_body'] ?? ''));
        $whatsappMessage = trim((string) ($decoded['whatsapp_message'] ?? ''));
        $purposeRaw = trim((string) ($decoded['template_purpose'] ?? ''));

        if ($title === '' && $mainBody === '' && $whatsappMessage === '' && $purposeRaw === '') {
            throw new \InvalidArgumentException('Empty AI assist payload.');
        }

        $purpose = null;
        $suggested = null;

        if ($purposeRaw !== '') {
            $purpose = AnnouncementWhatsAppTemplatePurpose::tryFrom($purposeRaw);

            if ($purpose === null) {
                throw new \InvalidArgumentException('Invalid template purpose.');
            }

            $matched = $templates->findByPurpose($purpose->value);

            if ($matched instanceof WhatsAppTemplate) {
                $suggested = [
                    'id' => (int) $matched->id,
                    'label' => (string) $matched->label,
                    'purpose' => $purpose->value,
                ];
            }
        }

        $sanitizedBody = $mainBody !== ''
            ? SanitizeAnnouncementHtml::handle($mainBody)
            : '';

        if ($whatsappMessage !== '') {
            $whatsappMessage = AnnouncementWhatsAppMessage::normalize($whatsappMessage);
            $whatsappMessage = mb_substr($whatsappMessage, 0, AnnouncementWhatsAppMessage::MAX_LENGTH);
        }

        return new self(
            title: mb_substr($title, 0, 255),
            mainBodyHtml: $sanitizedBody,
            whatsappMessage: $whatsappMessage,
            templatePurpose: $purpose,
            suggestedTemplate: $suggested,
        );
    }

    /**
     * @return array{
     *     title: string,
     *     main_body: string,
     *     whatsapp_message: string,
     *     template_purpose: string|null,
     *     suggested_template: array{id: int, label: string, purpose: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'main_body' => $this->mainBodyHtml,
            'whatsapp_message' => $this->whatsappMessage,
            'template_purpose' => $this->templatePurpose?->value,
            'suggested_template' => $this->suggestedTemplate,
        ];
    }
}
