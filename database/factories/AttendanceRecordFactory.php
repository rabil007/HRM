<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'date' => $date->format('Y-m-d'),
            'clock_in' => $date->format('Y-m-d').' 08:00:00',
            'clock_out' => $date->format('Y-m-d').' 17:00:00',
            'hours_worked' => 9,
            'overtime_hours' => 0,
            'late_minutes' => 0,
            'source' => AttendanceRecord::SOURCE_MANUAL,
            'status' => AttendanceRecord::STATUS_PRESENT,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }
}
