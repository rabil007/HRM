<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartDocumentSigningFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.recipient-requests.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'document_signing_preset_id' => [
                'required',
                'integer',
                Rule::exists('document_signing_presets', 'id')
                    ->where('company_id', $companyId)
                    ->where('status', 'active'),
            ],
        ];
    }
}
