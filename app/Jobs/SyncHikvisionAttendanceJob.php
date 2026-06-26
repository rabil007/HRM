<?php

namespace App\Jobs;

use App\Services\HikvisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SyncHikvisionAttendanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ?string $date = null) {}

    public function handle(HikvisionService $hikvision): void
    {
        $timezone = (string) config('app.timezone', 'UTC');

        if (filled($this->date)) {
            $day = Carbon::parse($this->date, $timezone)->startOfDay();
            $hikvision->syncAttendanceForDay($day);

            if ($day->isToday()) {
                $hikvision->syncAttendanceForDay($day->copy()->subDay());
            }

            return;
        }

        $hikvision->syncAttendanceForScheduledDays();
    }
}
