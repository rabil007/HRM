<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Support\Announcements\Actions\PersistAnnouncement;
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
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*.type' => ['required', Rule::in(AnnouncementAudienceType::values())],
            'audiences.*.id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'publish_mode' => ['required', Rule::in(['draft', 'schedule', 'send_now'])],
            'scheduled_at' => ['nullable', 'required_if:publish_mode,schedule', 'date', 'after:now'],
        ];
    }

    protected function passedValidation(): void
    {
        PersistAnnouncement::assertChannels($this->input('channels', []));
    }
}
