<?php

namespace App\Http\Requests\Notifications;

use App\Rules\ValidWebPushEndpoint;
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
            'endpoint' => ['required', 'string', 'max:500', new ValidWebPushEndpoint],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'subscribable_id' => ['prohibited'],
            'subscribable_type' => ['prohibited'],
        ];
    }
}
