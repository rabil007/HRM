<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrewRankPolicy;
use App\Models\Rank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewRankPolicy>
 */
class CrewRankPolicyFactory extends Factory
{
    protected $model = CrewRankPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'rank_id' => Rank::query()->create([
                'name' => 'Policy Rank '.$this->faker->unique()->uuid(),
                'is_active' => true,
                'max_tour_of_duty_days' => 90,
            ])->id,
            'tour_of_duty_days' => 90,
            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }

    public function forRank(Rank $rank): static
    {
        return $this->state(fn (): array => [
            'rank_id' => $rank->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
