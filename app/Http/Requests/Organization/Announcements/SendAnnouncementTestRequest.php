<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAnnouncementTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('announcements.publish');
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
            'whatsapp_link' => [
                'nullable',
                'string',
                'url:http,https',
                'max:2048',
            ],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [
                'required',
                Rule::in([
                    AnnouncementChannel::Email->value,
                    AnnouncementChannel::WhatsApp->value,
                ]),
            ],
            'announcement_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Never trust client-supplied destinations or company identity.
        $this->request->remove('email');
        $this->request->remove('phone');
        $this->request->remove('user_id');
        $this->request->remove('employee_id');
        $this->request->remove('company_id');

        $channels = $this->input('channels');

        if (! is_array($channels)) {
            return;
        }

        $channels = array_values(array_unique(array_map('strval', $channels)));

        if (! in_array(AnnouncementChannel::WhatsApp->value, $channels, true)) {
            $this->merge(['whatsapp_link' => null]);
        }

        $this->merge(['channels' => $channels]);
    }
}
