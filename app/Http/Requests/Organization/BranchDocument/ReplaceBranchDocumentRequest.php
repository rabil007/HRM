<?php

namespace App\Http\Requests\Organization\BranchDocument;

use App\Http\Requests\Organization\CompanyDocument\Concerns\HasCompanyDocumentRules;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceBranchDocumentRequest extends FormRequest
{
    use HasCompanyDocumentRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $branch = $this->route('branch');
        $companyDocument = $this->route('companyDocument');

        if (! $branch instanceof Branch || ! $companyDocument instanceof CompanyDocument) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $company = Company::query()->whereKey($companyId)->first();

        if (! $company instanceof Company) {
            return false;
        }

        app(CompanyDocumentAccess::class)->authorizeBranchDocument(
            $this->user(),
            $company,
            $branch,
            $companyDocument,
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
