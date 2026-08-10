<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'signature_request_ids' => ['nullable', 'array', 'min:1'],
            'signature_request_ids.*' => ['integer', 'distinct'],
            'employee_ids' => ['nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct'],
            'document_type_key' => ['nullable', 'string', 'max:64'],
            'format' => ['nullable', 'string', 'in:csv,xlsx,excel'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->signatureRequestIds() === [] && $this->employeeIds() === []) {
                $validator->errors()->add(
                    'signature_request_ids',
                    'Select at least one employee or signature request to export.',
                );
            }
        });
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

    /**
     * @return list<int>
     */
    public function employeeIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $this->input('employee_ids', [])));

        return $ids;
    }

    public function exportFormat(): string
    {
        $format = strtolower((string) $this->input('format', 'csv'));

        return in_array($format, ['xlsx', 'excel'], true) ? 'xlsx' : 'csv';
    }
}
