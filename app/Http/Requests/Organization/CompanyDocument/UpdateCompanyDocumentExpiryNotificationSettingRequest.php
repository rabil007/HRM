<?php

namespace App\Http\Requests\Organization\CompanyDocument;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyDocumentExpiryNotificationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'to_user_ids' => ['nullable', 'array'],
            'to_user_ids.*' => ['integer'],
            'cc_user_ids' => ['nullable', 'array'],
            'cc_user_ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [];
    }
}
