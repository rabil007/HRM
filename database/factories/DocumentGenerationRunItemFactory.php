<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentGenerationRunItem>
 */
class DocumentGenerationRunItemFactory extends Factory
{
    protected $model = DocumentGenerationRunItem::class;

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
            'document_generation_run_id' => DocumentGenerationRun::factory(),
            'employee_id' => Employee::factory(),
            'status' => 'pending',
            'document_instance_id' => null,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }
}
