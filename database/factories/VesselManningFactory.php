<?php

namespace Database\Factories;

use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VesselManning>
 */
class VesselManningFactory extends Factory
{
    protected $model = VesselManning::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via forCompany()');
            },
            'vessel_id' => static function (): int {
                return Vessel::query()->create([
                    'name' => fake()->unique()->words(2, true).' OSV',
                    'vessel_type_id' => VesselType::query()->create([
                        'name' => 'V '.Str::uuid()->toString(),
                        'is_active' => true,
                    ])->id,
                    'is_active' => true,
                ])->id;
            },
            'rank_id' => static function (): int {
                return Rank::query()->create([
                    'name' => 'R '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'required_count' => fake()->numberBetween(1, 5),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }

    public function forVessel(Vessel $vessel): static
    {
        return $this->state(fn () => [
            'vessel_id' => $vessel->id,
        ]);
    }
}
