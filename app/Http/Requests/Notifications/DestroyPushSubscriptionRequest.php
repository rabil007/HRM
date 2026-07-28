<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class DestroyPushSubscriptionRequest extends FormRequest
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
        ];
    }
}
