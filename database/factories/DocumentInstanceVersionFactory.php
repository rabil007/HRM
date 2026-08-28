<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentInstanceVersion>
 */
class DocumentInstanceVersionFactory extends Factory
{
    protected $model = DocumentInstanceVersion::class;

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
            'document_instance_id' => DocumentInstance::factory(),
            'version' => 1,
            'stage' => 'generated',
            'file_path' => 'document-instances/1/'.Str::uuid().'.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', 'dummy'),
            'created_by' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }

    public function forInstance(DocumentInstance $instance): static
    {
        return $this->state(fn () => [
            'document_instance_id' => $instance->id,
            'company_id' => $instance->company_id,
        ]);
    }
}
