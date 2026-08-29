<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentSigningPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.signing-presets.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('document_signing_presets', 'name')
                    ->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'steps' => ['required', 'array', 'min:1', 'max:8'],
            'steps.*.recipient_role' => ['required', 'in:subject,manager,company_signatory'],
            'steps.*.target_type' => ['nullable', 'in:subject_employee,department_manager,specific_user'],
            'steps.*.target_user_id' => ['nullable', 'integer'],
            'steps.*.step_label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
