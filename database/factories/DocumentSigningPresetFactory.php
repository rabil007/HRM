<?php

namespace Database\Factories;

use App\Enums\DocumentSigningPresetStatus;
use App\Models\Company;
use App\Models\DocumentSigningPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSigningPreset>
 */
class DocumentSigningPresetFactory extends Factory
{
    protected $model = DocumentSigningPreset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'status' => DocumentSigningPresetStatus::Active,
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentSigningPresetStatus::Inactive,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }
}
