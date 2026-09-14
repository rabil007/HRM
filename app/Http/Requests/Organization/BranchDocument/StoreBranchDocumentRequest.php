<?php

namespace App\Http\Requests\Organization\BranchDocument;

use App\Http\Requests\Organization\CompanyDocument\Concerns\HasCompanyDocumentRules;
use App\Models\Branch;
use App\Models\Company;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchDocumentRequest extends FormRequest
{
    use HasCompanyDocumentRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        if (! $branch instanceof Branch) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $company = Company::query()->whereKey($companyId)->first();

        if (! $company instanceof Company) {
            return false;
        }

        app(CompanyDocumentAccess::class)->authorizeBranch(
            $this->user(),
            $company,
            $branch,
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
            ...$this->documentMetadataRules(),
            'file' => $this->singleFileRules(),
        ];
    }
}
