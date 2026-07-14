<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use Illuminate\Foundation\Http\FormRequest;

class ExportBulkDocumentSignatureEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulk_documents.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signature_request_ids' => ['required', 'array', 'min:1'],
            'signature_request_ids.*' => ['integer', 'distinct'],
            'document_type_key' => ['nullable', 'string', 'max:64'],
            'format' => ['nullable', 'string', 'in:csv,xlsx,excel'],
        ];
    }

    /**
     * @return list<int>
     */
    public function signatureRequestIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $this->input('signature_request_ids', [])));

        return $ids;
    }

    public function exportFormat(): string
    {
        $format = strtolower((string) $this->input('format', 'csv'));

        return in_array($format, ['xlsx', 'excel'], true) ? 'xlsx' : 'csv';
    }
}
