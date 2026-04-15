<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => function () {
                $code = strtoupper((string) $this->faker->unique()->lexify('??'));

                $country = Country::query()->create([
                    'code' => $code,
                    'name' => "Test {$code}",
                    'dial_code' => '+999',
                    'is_active' => true,
                ]);

                $currency = Currency::query()->create([
                    'code' => $code,
                    'name' => "Test {$code}",
                    'symbol' => '$',
                    'is_active' => true,
                ]);

                return Company::query()->create([
                    'name' => "Company {$code}",
                    'slug' => strtolower($code),
                    'working_days' => [1, 2, 3, 4, 5],
                    'country_id' => $country->id,
                    'currency_id' => $currency->id,
                    'timezone' => 'Asia/Dubai',
                    'payroll_cycle' => 'monthly',
                    'status' => 'active',
                ])->id;
            },
            'user_id' => null,
            'branch_id' => null,
            'department_id' => null,
            'position_id' => null,
            'manager_id' => null,
            'employee_no' => (string) $this->faker->unique()->numerify('EMP####'),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'date_of_birth' => $this->faker->optional()->date(),
            'place_of_birth' => $this->faker->optional()->city(),
            'gender' => $this->faker->optional()->randomElement(['male', 'female', 'other']),
            'religion' => $this->faker->optional()->randomElement(['Muslim', 'Christian', 'Hindu', 'Catholic', 'Other']),
            'nationality' => $this->faker->optional()->country(),
            'marital_status' => $this->faker->optional()->randomElement(['single', 'married', 'divorced', 'widowed']),
            'spouse_name' => $this->faker->optional()->name(),
            'spouse_birthdate' => $this->faker->optional()->date(),
            'dependent_children_count' => $this->faker->optional()->numberBetween(0, 6),
            'personal_email' => $this->faker->optional()->safeEmail(),
            'work_email' => $this->faker->optional()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'nearest_airport' => $this->faker->optional()->city(),
            'phone_home_country' => $this->faker->optional()->phoneNumber(),
            'cv_source' => $this->faker->optional()->randomElement(['LinkedIn', 'Referral', 'Website', 'Agency']),
            'emergency_contact' => $this->faker->optional()->name(),
            'emergency_phone' => $this->faker->optional()->phoneNumber(),
            'emergency_contact_home_country' => $this->faker->optional()->name(),
            'emergency_phone_home_country' => $this->faker->optional()->phoneNumber(),
            'address' => $this->faker->optional()->address(),
            'hire_date' => $this->faker->date(),
            'probation_end_date' => $this->faker->optional()->date(),
            'contract_type' => $this->faker->randomElement(['limited', 'unlimited', 'part_time', 'contract']),
            'contract_end_date' => $this->faker->optional()->date(),
            'labor_contract_id' => $this->faker->optional()->bothify('LCID-########'),
            'basic_salary' => $this->faker->randomFloat(2, 0, 30000),
            'housing_allowance' => $this->faker->randomFloat(2, 0, 15000),
            'transport_allowance' => $this->faker->randomFloat(2, 0, 5000),
            'other_allowances' => $this->faker->randomFloat(2, 0, 5000),
            'bank_name' => $this->faker->optional()->company(),
            'bank_account_name' => $this->faker->optional()->name(),
            'iban' => $this->faker->optional()->iban(),
            'visa_number' => $this->faker->optional()->bothify('VISA-########'),
            'visa_expiry' => $this->faker->optional()->date(),
            'visa_type' => $this->faker->optional()->word(),
            'emirates_id' => $this->faker->optional()->bothify('###-####-#######-#'),
            'emirates_id_expiry' => $this->faker->optional()->date(),
            'passport_number' => $this->faker->optional()->bothify('P########'),
            'passport_issued_at' => $this->faker->optional()->date(),
            'passport_expiry' => $this->faker->optional()->date(),
            'work_permit_number' => $this->faker->optional()->bothify('WP-########'),
            'work_permit_expiry' => $this->faker->optional()->date(),
            'labor_card_number' => $this->faker->optional()->bothify('LC-########'),
            'labor_card_expiry' => $this->faker->optional()->date(),
            'mohre_uid' => $this->faker->optional()->bothify('MOHRE-########'),
            'status' => $this->faker->randomElement(['active', 'inactive', 'on_leave', 'terminated']),
            'termination_date' => null,
            'termination_reason' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state([
            'company_id' => $company->id,
        ]);
    }

    public function withUser(User $user): static
    {
        return $this->state([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);
    }

    public function inBranch(Branch $branch): static
    {
        return $this->state([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function inDepartment(Department $department): static
    {
        return $this->state([
            'company_id' => $department->company_id,
            'department_id' => $department->id,
        ]);
    }

    public function inPosition(Position $position): static
    {
        return $this->state([
            'company_id' => $position->company_id,
            'position_id' => $position->id,
            'department_id' => $position->department_id,
        ]);
    }
}
