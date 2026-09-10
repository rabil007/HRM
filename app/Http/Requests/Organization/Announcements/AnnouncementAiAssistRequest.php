<?php

namespace App\Http\Requests\Organization\Announcements;

use App\Enums\AnnouncementAiAssistAction;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementAiAssistRequest extends FormRequest
{
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
            'action' => ['required', Rule::enum(AnnouncementAiAssistAction::class)],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:50000'],
            'whatsapp_message' => [
                'nullable',
                'string',
                'max:'.AnnouncementWhatsAppMessage::MAX_LENGTH,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Never trust client tenant/identity or recipient payloads.
        $this->request->remove('company_id');
        $this->request->remove('employee_id');
        $this->request->remove('user_id');
        $this->request->remove('recipients');
        $this->request->remove('audiences');
        $this->request->remove('whatsapp_template_id');
        $this->request->remove('template_id');
        $this->request->remove('meta_name');
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $action = (string) $this->input('action', '');

            if ($action === AnnouncementAiAssistAction::Generate->value
                && blank($this->input('instructions'))) {
                $validator->errors()->add('instructions', 'Provide instructions to generate content.');
            }
        });
    }
}
