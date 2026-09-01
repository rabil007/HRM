<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use App\Support\BulkDocuments\GenerateDocumentTypeKey;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DeleteBulkDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulk_documents.delete') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'document_type_key' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, Closure $fail) use ($companyId): void {
                    if (! is_string($value) || ! GenerateDocumentTypeKey::isAllowedForCompany($companyId, $value)) {
                        $fail('The selected document type is invalid.');
                    }
                },
            ],
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function documentIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('document_ids', [])));
    }
}
