<?php

namespace App\Http\Requests\Settings;

use App\Support\Platform\PlatformAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class TestApplicationAiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PlatformAuthorization::canManage($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'openai_api_key' => ['prohibited'],
            'openrouter_api_key' => ['prohibited'],
            'api_key' => ['prohibited'],
            'model' => ['prohibited'],
        ];
    }
}
