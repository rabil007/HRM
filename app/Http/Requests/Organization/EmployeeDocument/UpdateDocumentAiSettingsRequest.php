<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use App\Enums\DocumentAiMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.ai.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(DocumentAiMode::class)],
            'company_id' => ['prohibited'],
        ];
    }
}
