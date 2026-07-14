<?php

namespace App\Http\Requests\Organization\CompanyDocument;

use App\Models\Company;
use App\Rules\CompanyDocumentFile;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ReplaceCompanyDocumentRequest extends FormRequest
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
            CompanyDocumentAccess::Abilities['update'],
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
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max('20mb'), 'extensions:pdf,jpg,jpeg,png', new CompanyDocumentFile],
        ];
    }
}
