<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Support\Settings\CompanyDocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyDocumentSetting>
 */
class CompanyDocumentSettingFactory extends Factory
{
    protected $model = CompanyDocumentSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? 1,
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signatory_name' => fake()->name(),
            'signatory_title' => 'HR Manager',
            'signature_path' => null,
            'stamp_path' => null,
            'footer_text' => null,
            'effective_from' => null,
            'effective_to' => null,
            'updated_by' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }

    public function salaryCertificate(): static
    {
        return $this->state(fn (): array => [
            'document_type' => CompanyDocumentType::SalaryCertificate,
        ]);
    }
}
