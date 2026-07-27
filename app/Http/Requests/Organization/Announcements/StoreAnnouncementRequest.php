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
            'whatsapp_link' => [
                'nullable',
                'string',
                'url:http,https',
                'max:2048',
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

        // #region agent log
        $rawLink = $this->input('whatsapp_link');
        file_put_contents(
            base_path('.cursor/debug-17d3aa.log'),
            json_encode([
                'sessionId' => '17d3aa',
                'runId' => 'pre-fix',
                'hypothesisId' => 'C-D-E',
                'location' => 'StoreAnnouncementRequest.php:prepareForValidation',
                'message' => 'Incoming announcement create validation inputs',
                'data' => [
                    'channels' => $channels,
                    'has_whatsapp' => in_array(AnnouncementChannel::WhatsApp->value, $channels, true),
                    'whatsapp_link_type' => gettype($rawLink),
                    'whatsapp_link_is_null' => $rawLink === null,
                    'whatsapp_link_length' => is_string($rawLink) ? strlen($rawLink) : null,
                    'whatsapp_link_trimmed_length' => is_string($rawLink) ? strlen(trim($rawLink)) : null,
                    'all_input_keys' => array_keys($this->all()),
                    'run' => 'post-fix',
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ], JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND
        );
        // #endregion

        if (! in_array(AnnouncementChannel::WhatsApp->value, $channels, true)) {
            $this->merge(['whatsapp_link' => null]);
        }
    }

    protected function passedValidation(): void
    {
        PersistAnnouncement::assertChannels($this->input('channels', []));
    }
}
