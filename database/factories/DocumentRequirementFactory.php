<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequirement>
 */
class DocumentRequirementFactory extends Factory
{
    protected $model = DocumentRequirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => function (): int {
                $code = strtoupper((string) fake()->unique()->lexify('??'));
                $country = Country::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => "Test {$code}", 'dial_code' => '+999', 'is_active' => true],
                );
                $currency = Currency::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => "Test {$code}", 'symbol' => '$', 'is_active' => true],
                );

                return Company::query()->create([
                    'name' => "Company {$code}",
                    'slug' => strtolower($code).'-'.fake()->unique()->numberBetween(1000, 9999),
                    'working_days' => [1, 2, 3, 4, 5],
                    'country_id' => $country->id,
                    'currency_id' => $currency->id,
                    'timezone' => 'Asia/Dubai',
                    'payroll_cycle' => 'monthly',
                    'status' => 'active',
                ])->id;
            },
            'document_type_id' => fn (): int => DocumentType::query()->create([
                'title' => fake()->unique()->words(3, true).' '.uniqid(),
                'is_active' => true,
            ])->id,
            'required_for_all' => false,
            'require_issue_date' => false,
            'require_expiry_date' => false,
            'require_document_number' => false,
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }

    public function forDocumentType(DocumentType $documentType): static
    {
        return $this->state(fn (): array => [
            'document_type_id' => $documentType->id,
        ]);
    }

    public function requiredForAll(): static
    {
        return $this->state(fn (): array => [
            'required_for_all' => true,
            'is_active' => true,
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
