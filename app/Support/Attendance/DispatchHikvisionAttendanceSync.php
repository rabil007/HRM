<?php

namespace App\Support\Attendance;

use App\Jobs\SyncCompanyHikvisionAttendanceJob;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class DispatchHikvisionAttendanceSync
{
    public function dispatchForWindow(CarbonInterface $from, CarbonInterface $to): int
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $rangeStart = $from->copy()->timezone($timezone)->startOfDay();
        $rangeEnd = $to->copy()->timezone($timezone)->endOfDay();
        $fromString = $rangeStart->toDateTimeString();
        $toString = $rangeEnd->toDateTimeString();

        $dispatched = 0;

        foreach ($this->companyIdsForSync() as $companyId) {
            SyncCompanyHikvisionAttendanceJob::dispatch((int) $companyId, $fromString, $toString);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * @return Collection<int, int>
     */
    public function companyIdsForSync(): Collection
    {
        return Employee::query()
            ->where('status', 'active')
            ->whereNotNull('hikvision_person_id')
            ->distinct()
            ->pluck('company_id')
            ->map(fn (mixed $companyId): int => (int) $companyId)
            ->values();
    }
}
