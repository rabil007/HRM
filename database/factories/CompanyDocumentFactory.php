<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyDocument>
 */
class CompanyDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
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
            'document_type_id' => fn () => DocumentType::query()->firstOrCreate(
                ['title' => 'Certificate'],
                ['is_active' => true],
            )->id,
            'title' => fake()->words(3, true),
            'document_number' => fake()->optional()->bothify('DOC-####'),
            'issue_date' => fake()->optional()->date(),
            'expiry_date' => fake()->optional()->dateTimeBetween('+31 days', '+2 years')?->format('Y-m-d'),
            'notes' => fake()->optional()->sentence(),
            'file_path' => 'company-documents/test/document.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', 'document'),
            'current_version' => 1,
            'uploaded_by' => User::factory(),
        ];
    }
}
