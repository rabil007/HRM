<?php

namespace Database\Factories;

use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\Company;
use App\Models\DocumentWorkflowPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentWorkflowPreset>
 */
class DocumentWorkflowPresetFactory extends Factory
{
    protected $model = DocumentWorkflowPreset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'status' => DocumentWorkflowPresetStatus::Active,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentWorkflowPresetStatus::Inactive,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }
}
