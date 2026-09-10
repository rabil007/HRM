<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\Announcement;
use App\Models\WhatsAppTemplate;
use InvalidArgumentException;

final class BuildAnnouncementWhatsAppContent
{
    public function __construct(private ResolveAnnouncementWhatsAppTemplate $resolveTemplate) {}

    /**
     * @return array{
     *     template: WhatsAppTemplate,
     *     profile: AnnouncementWhatsAppPayloadProfile,
     *     components: list<array{type: string, parameters: list<array{type: string, text: string}>}>,
     *     preview: array{
     *         template_id: int,
     *         template_label: string,
     *         template_name: string,
     *         template_language: string,
     *         payload_profile: string,
     *         header_type: string,
     *         header_text: string|null,
     *         body_text: string,
     *         resolved_message: string,
     *         view_link: string|null,
     *         available: bool,
     *         message: string|null
     *     }
     * }|null
     */
    public function handle(Announcement $announcement, ?int $templateId = null): ?array
    {
        $template = $this->resolveTemplate->handle($announcement, $templateId);

        if ($template === null) {
            return null;
        }

        $profile = $this->resolveTemplate->profileFor($template);
        $announcement->loadMissing('company:id,name');

        try {
            return match ($profile) {
                AnnouncementWhatsAppPayloadProfile::TitleBodyV2 => $this->buildTitleBodyV2($announcement, $template, $profile),
                AnnouncementWhatsAppPayloadProfile::LegacyV1 => $this->buildLegacyV1($announcement, $template, $profile),
            };
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{
     *     template_id: int|null,
     *     template_label: string|null,
     *     template_name: string,
     *     template_language: string,
     *     payload_profile: string|null,
     *     header_type: string,
     *     header_text: string|null,
     *     body_text: string,
     *     resolved_message: string|null,
     *     view_link: string|null,
     *     available: bool,
     *     message: string|null
     * }
     */
    public function previewOrError(Announcement $announcement, ?int $templateId = null): array
    {
        $optionalLink = AnnouncementWhatsAppMessage::optionalLink($announcement);
        $template = $this->resolveTemplate->handle($announcement, $templateId);

        if (
            $template !== null
            && $optionalLink !== null
            && $this->resolveTemplate->profileFor($template) === AnnouncementWhatsAppPayloadProfile::TitleBodyV2
            && ! AnnouncementWhatsAppMessage::optionalLinkFits($optionalLink)
        ) {
            return [
                'template_id' => (int) $template->id,
                'template_label' => (string) $template->label,
                'template_name' => (string) $template->meta_name,
                'template_language' => (string) $template->meta_language,
                'payload_profile' => AnnouncementWhatsAppPayloadProfile::TitleBodyV2->value,
                'header_type' => $template->header_type->value,
                'header_text' => null,
                'body_text' => '',
                'resolved_message' => null,
                'view_link' => $optionalLink,
                'available' => false,
                'message' => 'The WhatsApp link is too long to fit in the WhatsApp message body.',
            ];
        }

        $built = $this->handle($announcement, $templateId);

        if ($built === null) {
            $selected = $templateId ?? $announcement->whatsapp_template_id;

            return [
                'template_id' => $selected !== null ? (int) $selected : null,
                'template_label' => null,
                'template_name' => ResolveAnnouncementWhatsAppTemplate::LEGACY_SLUG,
                'template_language' => 'en',
                'payload_profile' => null,
                'header_type' => WhatsAppTemplateHeaderType::None->value,
                'header_text' => null,
                'body_text' => '',
                'resolved_message' => null,
                'view_link' => $optionalLink,
                'available' => false,
                'message' => $selected !== null
                    ? 'The selected WhatsApp template is missing, disabled, or incompatible.'
                    : 'WhatsApp announcement template is not configured.',
            ];
        }

        return $built['preview'];
    }

    /**
     * @return array{
     *     template: WhatsAppTemplate,
     *     profile: AnnouncementWhatsAppPayloadProfile,
     *     components: list<array{type: string, parameters: list<array{type: string, text: string}>}>,
     *     preview: array<string, mixed>
     * }
     */
    private function buildLegacyV1(
        Announcement $announcement,
        WhatsAppTemplate $template,
        AnnouncementWhatsAppPayloadProfile $profile,
    ): array {
        $companyName = AnnouncementWhatsAppMessage::templateParameter(
            (string) ($announcement->company?->name ?? config('app.name')),
        );
        $title = AnnouncementWhatsAppMessage::templateParameter((string) $announcement->title);
        $shortSummary = AnnouncementWhatsAppMessage::for($announcement);
        $priority = AnnouncementWhatsAppMessage::templateParameter($announcement->priority->label());
        $linkParameter = AnnouncementWhatsAppMessage::templateParameter(
            AnnouncementWhatsAppMessage::viewLink($announcement),
        );

        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $companyName],
                    ['type' => 'text', 'text' => $title],
                    ['type' => 'text', 'text' => $shortSummary],
                    ['type' => 'text', 'text' => $priority],
                    ['type' => 'text', 'text' => $linkParameter],
                ],
            ],
        ];

        $bodyText = $this->fillBodyPreview(
            (string) $template->body_preview,
            [
                '{{company}}' => $companyName,
                '{{title}}' => $title,
                '{{message}}' => $shortSummary,
                '{{priority}}' => $priority,
                '{{url}}' => $linkParameter,
                '{{1}}' => $companyName,
                '{{2}}' => $title,
                '{{3}}' => $shortSummary,
                '{{4}}' => $priority,
                '{{5}}' => $linkParameter,
            ],
            "Hello,\nA company notice from {$companyName} is available for you.\n\nTitle: {$title}\nSummary: {$shortSummary}\nPriority: {$priority}\nView link: {$linkParameter}",
        );

        return [
            'template' => $template,
            'profile' => $profile,
            'components' => $components,
            'preview' => $this->previewArray(
                $template,
                $profile,
                headerText: null,
                bodyText: $bodyText,
                resolvedMessage: $shortSummary,
                viewLink: AnnouncementWhatsAppMessage::optionalLink($announcement) ?? AnnouncementWhatsAppMessage::EMPTY_VIEW_LINK,
            ),
        ];
    }

    /**
     * @return array{
     *     template: WhatsAppTemplate,
     *     profile: AnnouncementWhatsAppPayloadProfile,
     *     components: list<array{type: string, parameters: list<array{type: string, text: string}>}>,
     *     preview: array<string, mixed>
     * }
     */
    private function buildTitleBodyV2(
        Announcement $announcement,
        WhatsAppTemplate $template,
        AnnouncementWhatsAppPayloadProfile $profile,
    ): array {
        $headerTitle = AnnouncementWhatsAppMessage::metaTextHeader((string) $announcement->title);
        $bodyMessage = AnnouncementWhatsAppMessage::resolvedBodyWithOptionalLink($announcement);
        $optionalLink = AnnouncementWhatsAppMessage::optionalLink($announcement);

        $components = [];

        if ($template->header_type === WhatsAppTemplateHeaderType::Text) {
            $components[] = [
                'type' => 'header',
                'parameters' => [
                    ['type' => 'text', 'text' => $headerTitle],
                ],
            ];
        }

        $components[] = [
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => $bodyMessage],
            ],
        ];

        $bodyText = $this->fillBodyPreview(
            (string) $template->body_preview,
            [
                '{{title}}' => $headerTitle,
                '{{message}}' => $bodyMessage,
                '{{1}}' => $bodyMessage,
            ],
            $bodyMessage,
        );

        return [
            'template' => $template,
            'profile' => $profile,
            'components' => $components,
            'preview' => $this->previewArray(
                $template,
                $profile,
                headerText: $template->header_type === WhatsAppTemplateHeaderType::Text ? $headerTitle : null,
                bodyText: $bodyText,
                resolvedMessage: $bodyMessage,
                viewLink: $optionalLink,
            ),
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function fillBodyPreview(string $bodyPreview, array $replacements, string $fallback): string
    {
        if (! filled($bodyPreview)) {
            return $fallback;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $bodyPreview);
    }

    /**
     * @return array{
     *     template_id: int,
     *     template_label: string,
     *     template_name: string,
     *     template_language: string,
     *     payload_profile: string,
     *     header_type: string,
     *     header_text: string|null,
     *     body_text: string,
     *     resolved_message: string,
     *     view_link: string|null,
     *     available: bool,
     *     message: string|null
     * }
     */
    private function previewArray(
        WhatsAppTemplate $template,
        AnnouncementWhatsAppPayloadProfile $profile,
        ?string $headerText,
        string $bodyText,
        string $resolvedMessage,
        ?string $viewLink,
    ): array {
        return [
            'template_id' => (int) $template->id,
            'template_label' => (string) $template->label,
            'template_name' => (string) $template->meta_name,
            'template_language' => (string) $template->meta_language,
            'payload_profile' => $profile->value,
            'header_type' => $template->header_type->value,
            'header_text' => $headerText,
            'body_text' => $bodyText,
            'resolved_message' => $resolvedMessage,
            'view_link' => $viewLink,
            'available' => true,
            'message' => null,
        ];
    }
}
