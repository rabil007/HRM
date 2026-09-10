<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Enums\WhatsAppTemplateCategory;
use App\Http\Requests\Organization\Announcements\Concerns\ValidatesAnnouncementWhatsAppLink;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewAnnouncementChannelsRequest extends FormRequest
{
    use ValidatesAnnouncementWhatsAppLink;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->can('announcements.create')
            || $user->can('announcements.update')
        );
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'category' => ['required', Rule::in(AnnouncementCategory::values())],
            'priority' => ['required', Rule::in(AnnouncementPriority::values())],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(AnnouncementChannel::values())],
            'whatsapp_link' => $this->whatsappLinkRules(),
            'whatsapp_message' => [
                'nullable',
                'string',
                'max:'.AnnouncementWhatsAppMessage::MAX_LENGTH,
            ],
            'whatsapp_template_id' => [
                'nullable',
                'integer',
                Rule::exists('whatsapp_templates', 'id')->where(function ($query): void {
                    $query->where('category', WhatsAppTemplateCategory::Announcement->value)
                        ->where('enabled', true)
                        ->whereNotNull('payload_profile')
                        ->whereNull('deleted_at');
                }),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('company_id');

        $channels = $this->input('channels');

        if (! is_array($channels)) {
            return;
        }

        $channels = array_values(array_unique(array_map('strval', $channels)));

        if (! in_array(AnnouncementChannel::WhatsApp->value, $channels, true)) {
            $this->merge([
                'whatsapp_link' => null,
                'whatsapp_message' => null,
                'whatsapp_template_id' => null,
            ]);
        }

        $this->merge(['channels' => $channels]);
    }
}
