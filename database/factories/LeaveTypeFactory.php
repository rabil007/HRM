<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = Str::upper(Str::random(4));

        return [
            'name' => 'Leave '.$code,
            'code' => $code,
            'days_per_year' => 30,
            'carry_forward' => false,
            'max_carry_days' => 0,
            'color' => '#3b82f6',
            'status' => 'active',
        ];
    }
}
