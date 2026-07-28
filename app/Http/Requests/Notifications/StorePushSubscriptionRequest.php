<?php

namespace App\Http\Requests\Notifications;

use App\Rules\ValidWebPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', new ValidWebPushEndpoint],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'min:20', 'max:255'],
            'keys.auth' => ['required', 'string', 'min:8', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'subscribable_id' => ['prohibited'],
            'subscribable_type' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $encoding = $this->input('contentEncoding') ?? $this->input('content_encoding');

            if ($encoding !== null && ! in_array($encoding, ['aes128gcm', 'aesgcm'], true)) {
                $validator->errors()->add('contentEncoding', 'The content encoding is invalid.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['contentEncoding', 'content_encoding'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        Log::warning('Push subscription validation failed', [
            'error_keys' => array_keys($validator->errors()->toArray()),
            'messages' => $validator->errors()->all(),
            'has_endpoint' => $this->filled('endpoint'),
            'endpoint_length' => is_string($this->input('endpoint')) ? strlen((string) $this->input('endpoint')) : null,
            'has_keys' => is_array($this->input('keys')),
            'key_fields' => is_array($this->input('keys')) ? array_keys($this->input('keys')) : [],
            'content_encoding' => $this->input('contentEncoding') ?? $this->input('content_encoding'),
        ]);

        parent::failedValidation($validator);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}
     */
    public function subscriptionPayload(): array
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null, content_encoding?: string|null} $validated */
        $validated = $this->validated();

        return [
            'endpoint' => $validated['endpoint'],
            'keys' => [
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
            ],
            'contentEncoding' => $validated['contentEncoding']
                ?? $validated['content_encoding']
                ?? 'aes128gcm',
        ];
    }
}
