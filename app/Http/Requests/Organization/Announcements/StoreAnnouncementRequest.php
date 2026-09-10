<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Enums\WhatsAppTemplateCategory;
use App\Support\Announcements\Actions\PersistAnnouncement;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
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
            'whatsapp_link' => [
                'nullable',
                'string',
                'url:http,https',
                'max:2048',
            ],
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
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*.type' => ['required', Rule::in(AnnouncementAudienceType::values())],
            'audiences.*.id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
            'publish_mode' => ['required', Rule::in(['draft', 'schedule', 'send_now'])],
            'scheduled_at' => ['nullable', 'required_if:publish_mode,schedule', 'date', 'after:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $channels = array_values(array_map('strval', $this->input('channels', [])));

        if (! in_array(AnnouncementChannel::WhatsApp->value, $channels, true)) {
            $this->merge([
                'whatsapp_link' => null,
                'whatsapp_message' => null,
                'whatsapp_template_id' => null,
            ]);
        }
    }

    protected function passedValidation(): void
    {
        PersistAnnouncement::assertChannels($this->input('channels', []));
    }
}
