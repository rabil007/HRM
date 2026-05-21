<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTraining>
 */
class EmployeeTrainingFactory extends Factory
{
    protected $model = EmployeeTraining::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issueDate = fake()->dateTimeBetween('-3 years', '-1 month');

        return [
            'sort_order' => 0,
            'course_id' => Course::factory(),
            'issue_date' => $issueDate->format('Y-m-d'),
            'expiry_date' => fake()->optional(0.8)->dateTimeBetween($issueDate, '+5 years')?->format('Y-m-d'),
            'institute_center' => fake()->company().' MTC',
            'country_id' => null,
            'certificate_path' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }
}
