<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentGenerationRun>
 */
class DocumentGenerationRunFactory extends Factory
{
    protected $model = DocumentGenerationRun::class;

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
            'document_generation_template_id' => DocumentGenerationTemplate::factory(),
            'document_generation_template_version_id' => DocumentGenerationTemplateVersion::factory(),
            'filters' => null,
            'status' => 'queued',
            'total_targeted' => 1,
            'generated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'triggered_by' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }
}
