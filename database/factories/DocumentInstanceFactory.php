<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentInstance>
 */
class DocumentInstanceFactory extends Factory
{
    protected $model = DocumentInstance::class;

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
            'employee_id' => Employee::factory(),
            'employee_name_snapshot' => fake()->name(),
            'employee_no_snapshot' => 'EMP-'.fake()->numberBetween(1000, 9999),
            'document_generation_template_id' => DocumentGenerationTemplate::factory(),
            'document_generation_template_version_id' => DocumentGenerationTemplateVersion::factory(),
            'document_type_id' => null,
            'document_generation_run_id' => null,
            'employee_document_id' => null,
            'template_name_snapshot' => fake()->words(3, true).' Letter',
            'template_version_number' => 1,
            'title_snapshot' => fake()->words(3, true),
            'status' => 'generated',
            'current_version_id' => null,
            'generated_by' => null,
            'generated_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }
}
