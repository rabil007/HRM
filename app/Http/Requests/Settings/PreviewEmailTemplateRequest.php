<?php

namespace App\Http\Requests\Settings;

use App\Support\Platform\PlatformAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class PreviewEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PlatformAuthorization::canView($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'include_company_footer' => ['sometimes', 'boolean'],
        ];
    }
}
