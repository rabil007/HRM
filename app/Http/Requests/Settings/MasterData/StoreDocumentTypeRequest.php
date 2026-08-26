<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Http\Requests\Settings\MasterData\Concerns\AppliesDocumentRequirementRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDocumentTypeRequest extends FormRequest
{
    use AppliesDocumentRequirementRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->documentRequirementRules([
            'title' => [
                'required',
                'string',
                'max:200',
                Rule::unique('document_types', 'title')->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateSelectedRequirementScopes($validator);
            },
        ];
    }
}
