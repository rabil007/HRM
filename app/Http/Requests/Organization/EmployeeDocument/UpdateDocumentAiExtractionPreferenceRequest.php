<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentAiExtractionPreferenceRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'preference' => ['prohibited'],
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->boolean('enabled');
    }
}
