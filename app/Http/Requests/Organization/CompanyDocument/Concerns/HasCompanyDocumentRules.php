<?php

namespace App\Http\Requests\Organization\CompanyDocument\Concerns;

use App\Rules\CompanyDocumentFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

trait HasCompanyDocumentRules
{
    /**
     * @return array<string, mixed>
     */
    protected function documentMetadataRules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'title' => ['nullable', 'string', 'max:200'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function singleFileRules(): array
    {
        return [
            'required',
            File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('20mb'),
            'extensions:pdf,jpg,jpeg,png',
            new CompanyDocumentFile,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bulkUploadRules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*.document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'documents.*.title' => ['nullable', 'string', 'max:200'],
            'documents.*.document_number' => ['nullable', 'string', 'max:120'],
            'documents.*.issue_date' => ['nullable', 'date'],
            'documents.*.expiry_date' => ['nullable', 'date', 'after_or_equal:documents.*.issue_date'],
            'documents.*.notes' => ['nullable', 'string', 'max:2000'],
            'documents.*.file' => $this->singleFileRules(),
        ];
    }
}
