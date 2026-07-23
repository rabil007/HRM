<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewAnnouncementRecipientsRequest extends FormRequest
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
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(AnnouncementChannel::values())],
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*.type' => ['required', Rule::in(AnnouncementAudienceType::values())],
            'audiences.*.id' => ['nullable', 'integer'],
        ];
    }
}
