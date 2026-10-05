<?php

namespace Database\Factories;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\RecruitmentRequirementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentRequirementLine>
 */
class RecruitmentRequirementLineFactory extends Factory
{
    protected $model = RecruitmentRequirementLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'recruitment_requirement_id' => 1,
            'position_id' => 1,
            'required_headcount' => fake()->numberBetween(1, 10),
            'line_notes' => null,
            'status' => RequirementLineStatus::Open,
            'salary_min' => '4000.00',
            'salary_max' => '6000.00',
            'salary_currency_code' => 'AED',
        ];
    }
}
