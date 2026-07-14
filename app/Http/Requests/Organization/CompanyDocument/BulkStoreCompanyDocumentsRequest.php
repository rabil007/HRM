<?php

namespace App\Http\Requests\Organization\CompanyDocument;

use App\Models\Company;
use App\Rules\CompanyDocumentFile;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class BulkStoreCompanyDocumentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        app(CompanyDocumentAccess::class)->authorize(
            $this->user(),
            $company,
            CompanyDocumentAccess::Abilities['upload'],
        );

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*.document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'documents.*.title' => ['nullable', 'string', 'max:200'],
            'documents.*.document_number' => ['nullable', 'string', 'max:120'],
            'documents.*.issue_date' => ['nullable', 'date'],
            'documents.*.expiry_date' => ['nullable', 'date', 'after_or_equal:documents.*.issue_date'],
            'documents.*.notes' => ['nullable', 'string', 'max:2000'],
            'documents.*.file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('20mb'), 'extensions:pdf,jpg,jpeg,png', new CompanyDocumentFile],
        ];
    }
}
