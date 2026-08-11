<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vessel>
 */
class VesselFactory extends Factory
{
    protected $model = Vessel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via forCompany()');
            },
            'name' => fake()->unique()->words(2, true).' Vessel',
            'vessel_type_id' => static function (): int {
                return VesselType::query()->create([
                    'name' => 'VT '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }
}
