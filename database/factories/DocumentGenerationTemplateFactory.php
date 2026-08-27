<?php

namespace Database\Factories;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentGenerationTemplate>
 */
class DocumentGenerationTemplateFactory extends Factory
{
    protected $model = DocumentGenerationTemplate::class;

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
            'name' => $this->faker->unique()->words(3, true).' Letter',
            'description' => $this->faker->sentence(),
            'document_type_id' => null,
            'content' => "To Whom It May Concern,\n\nThis is to certify that {{employee_name}} (Employee No: {{employee_no}}) is employed with {{company_name}} as {{position_name}} in the {{department_name}} department.\n\nDate: {{today}}\n\nSincerely,\nHR Department",
            'status' => DocumentGenerationTemplateStatus::Draft,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => DocumentGenerationTemplateStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => DocumentGenerationTemplateStatus::Inactive,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }

    public function withDocumentType(?DocumentType $documentType = null): static
    {
        return $this->state(fn () => [
            'document_type_id' => $documentType?->id ?? DocumentType::query()->create([
                'title' => 'Test Document Type',
                'is_active' => true,
            ])->id,
        ]);
    }

    public function withAuthor(User $user): static
    {
        return $this->state(fn () => [
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
