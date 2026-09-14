<?php

namespace App\Http\Requests\Organization\CompanyDocument;

use App\Http\Requests\Organization\CompanyDocument\Concerns\HasCompanyDocumentRules;
use App\Models\Company;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceCompanyDocumentRequest extends FormRequest
{
    use HasCompanyDocumentRules;

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
            'file' => $this->singleFileRules(),
        ];
    }
}
