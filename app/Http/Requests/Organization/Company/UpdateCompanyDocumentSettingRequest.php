<?php

namespace App\Http\Requests\Organization\Company;

use App\Support\Settings\CompanyDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class UpdateCompanyDocumentSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('companies.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(CompanyDocumentType::all())],
            'signatory_name' => ['nullable', 'string', 'max:255'],
            'signatory_title' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'signature' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg'],
            'stamp' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg'],
            'remove_signature' => ['sometimes', 'boolean'],
            'remove_stamp' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     signatory_name: string|null,
     *     signatory_title: string|null,
     *     footer_text: string|null,
     *     effective_from: string|null,
     *     effective_to: string|null,
     *     remove_signature: bool,
     *     remove_stamp: bool,
     * }
     */
    public function settingPayload(): array
    {
        $validated = $this->validated();

        return [
            'signatory_name' => $validated['signatory_name'] ?? null,
            'signatory_title' => $validated['signatory_title'] ?? null,
            'footer_text' => $validated['footer_text'] ?? null,
            'effective_from' => $validated['effective_from'] ?? null,
            'effective_to' => $validated['effective_to'] ?? null,
            'remove_signature' => (bool) ($validated['remove_signature'] ?? false),
            'remove_stamp' => (bool) ($validated['remove_stamp'] ?? false),
        ];
    }

    /**
     * @return array{signature?: UploadedFile, stamp?: UploadedFile}
     */
    public function uploadFiles(): array
    {
        $files = [];

        if ($this->hasFile('signature')) {
            $files['signature'] = $this->file('signature');
        }

        if ($this->hasFile('stamp')) {
            $files['stamp'] = $this->file('stamp');
        }

        return $files;
    }

    public function documentType(): string
    {
        return (string) $this->validated('document_type');
    }
}
