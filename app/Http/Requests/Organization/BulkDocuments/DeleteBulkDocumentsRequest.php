<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $typeKeys = collect(BulkDocumentTypeRegistry::definitions())->pluck('key')->all();

        return [
            'document_type_key' => ['required', 'string', Rule::in($typeKeys)],
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
