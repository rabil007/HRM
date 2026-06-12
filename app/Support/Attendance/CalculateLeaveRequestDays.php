<?php

namespace App\Support\Attendance;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon as IlluminateCarbon;

class CalculateLeaveRequestDays
{
    public function __invoke(CarbonInterface|string $startDate, CarbonInterface|string $endDate): float
    {
        $start = $startDate instanceof CarbonInterface
            ? Carbon::instance($startDate)->startOfDay()
            : IlluminateCarbon::parse($startDate)->startOfDay();

        $end = $endDate instanceof CarbonInterface
            ? Carbon::instance($endDate)->startOfDay()
            : IlluminateCarbon::parse($endDate)->startOfDay();

        return (float) ($start->diffInDays($end) + 1);
    }
}
