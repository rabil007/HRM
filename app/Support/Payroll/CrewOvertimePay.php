<?php

namespace App\Support\Payroll;

final class CrewOvertimePay
{
    public const DIVISOR_DAYS = 365;

    public const MULTIPLIER = 1.25;

    /**
     * @return array{
     *     hours: float,
     *     monthly_salary: float,
     *     hour_rate: float,
     *     overtime_hourly_rate: float,
     *     overtime_pay: float
     * }
     */
    public function calculate(float $hours, float $monthlySalary): array
    {
        if ($hours <= 0) {
            return [
                'hours' => 0.0,
                'monthly_salary' => 0.0,
                'hour_rate' => 0.0,
                'overtime_hourly_rate' => 0.0,
                'overtime_pay' => 0.0,
            ];
        }

        $hourRate = $monthlySalary / self::DIVISOR_DAYS;
        $overtimeHourlyRate = $hourRate * self::MULTIPLIER;
        $overtimePay = round($hours * $overtimeHourlyRate, 2);

        return [
            'hours' => round($hours, 2),
            'monthly_salary' => round($monthlySalary, 2),
            'hour_rate' => round($hourRate, 2),
            'overtime_hourly_rate' => round($overtimeHourlyRate, 2),
            'overtime_pay' => $overtimePay,
        ];
    }
}
