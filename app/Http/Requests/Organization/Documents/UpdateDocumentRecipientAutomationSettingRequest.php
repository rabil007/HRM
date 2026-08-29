<?php

namespace App\Http\Requests\Organization\Documents;

use App\Support\Documents\RecipientRequests\Automation\DocumentRecipientAutomationPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDocumentRecipientAutomationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('documents.recipient-automation.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reminders_enabled' => ['required', 'boolean'],
            'reminder_days_before_expiry' => ['nullable', 'array', 'max:'.DocumentRecipientAutomationPolicy::MAX_REMINDERS],
            'reminder_days_before_expiry.*' => [
                'integer',
                'distinct',
                'min:'.DocumentRecipientAutomationPolicy::MIN_DAYS_BEFORE_EXPIRY,
                'max:'.DocumentRecipientAutomationPolicy::MAX_DAYS_BEFORE_EXPIRY,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->boolean('reminders_enabled')) {
                return;
            }

            $days = $this->input('reminder_days_before_expiry', []);

            if (! is_array($days) || $days === []) {
                $validator->errors()->add(
                    'reminder_days_before_expiry',
                    'At least one reminder day is required when automatic reminders are enabled.',
                );
            }
        });
    }
}
