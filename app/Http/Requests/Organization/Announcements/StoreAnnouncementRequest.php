<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Support\Announcements\Actions\PersistAnnouncement;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use App\Support\Announcements\SanitizeAnnouncementHtml;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'body_html' => ['required', 'string', 'max:65535'],
            'category' => ['required', Rule::in(AnnouncementCategory::values())],
            'priority' => ['required', Rule::in(AnnouncementPriority::values())],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(AnnouncementChannel::values())],
            'whatsapp_message' => [
                Rule::requiredIf(fn (): bool => in_array(AnnouncementChannel::WhatsApp->value, $this->input('channels', []), true)),
                'nullable',
                'string',
                'max:'.AnnouncementWhatsAppMessage::MAX_LENGTH,
            ],
            'whatsapp_link' => [
                Rule::requiredIf(fn (): bool => in_array(AnnouncementChannel::WhatsApp->value, $this->input('channels', []), true)),
                'nullable',
                'string',
                'max:2048',
                'url:http,https',
            ],
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*.type' => ['required', Rule::in(AnnouncementAudienceType::values())],
            'audiences.*.id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
            'publish_mode' => ['required', Rule::in(['draft', 'schedule', 'send_now'])],
            'scheduled_at' => ['nullable', 'required_if:publish_mode,schedule', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body_html.max' => 'Keep the announcement body under 65,535 characters.',
            'whatsapp_message.required' => 'Add a plain-text WhatsApp summary when WhatsApp delivery is selected.',
            'whatsapp_message.max' => 'Keep the WhatsApp summary within '.AnnouncementWhatsAppMessage::MAX_LENGTH.' characters.',
            'whatsapp_link.required' => 'Add a WhatsApp view link when WhatsApp delivery is selected.',
            'whatsapp_link.url' => 'Enter a valid http or https link for WhatsApp.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $body = $this->input('body_html');

                if (! is_string($body)) {
                    return;
                }

                $sanitized = SanitizeAnnouncementHtml::handle($body);

                if (AnnouncementWhatsAppMessage::fromHtml($sanitized) === '') {
                    $validator->errors()->add('body_html', 'Write a message before saving the announcement.');
                }
            },
        ];
    }

    protected function passedValidation(): void
    {
        PersistAnnouncement::assertChannels($this->input('channels', []));
    }
}
