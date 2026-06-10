<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeDeployment>
 */
class EmployeeDeploymentFactory extends Factory
{
    protected $model = EmployeeDeployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $joined = fake()->dateTimeBetween('-6 months', '-1 month');
        $disembarked = fake()->optional(0.6)->dateTimeBetween($joined, 'now');

        return [
            'sort_order' => 0,
            'rank_id' => static function (): int {
                return Rank::query()->create([
                    'name' => 'R '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'client_id' => static function (): int {
                return Client::query()->create([
                    'name' => 'C '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'company_visa_type_id' => static function (): int {
                return CompanyVisaType::query()->create([
                    'name' => 'VT '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'vessel_name' => fake()->words(2, true).' OSV',
            'hire_date' => fake()->optional()->date(),
            'arrived_date' => fake()->optional()->date(),
            'standby_from' => null,
            'standby_to' => null,
            'joined_date' => $joined->format('Y-m-d'),
            'disembarked_date' => $disembarked?->format('Y-m-d'),
            'travelled_date' => null,
            'remarks' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'rank_id' => $employee->rank_id,
        ]);
    }
}
