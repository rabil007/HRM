<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

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
            'endpoint' => ['required', 'string', 'url', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ];
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string|null}
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
            'contentEncoding' => $validated['contentEncoding'] ?? $validated['content_encoding'] ?? 'aesgcm',
        ];
    }
}
