<?php

namespace App\Support\Attendance;

use App\Jobs\SyncCompanyHikvisionAttendanceJob;
use App\Models\Employee;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class DispatchHikvisionAttendanceSync
{
    public function dispatchForWindow(CarbonInterface $from, CarbonInterface $to, ?int $companyId = null): int
    {
        $companyIds = $companyId === null ? $this->companyIdsForSync() : collect([$companyId]);
        $dispatched = 0;

        foreach ($companyIds as $id) {
            $timezone = CompanyTimezone::forCompany((int) $id);
            $rangeStart = $from->copy()->timezone($timezone)->startOfDay();
            $rangeEnd = $to->copy()->timezone($timezone)->endOfDay();

            SyncCompanyHikvisionAttendanceJob::dispatch(
                (int) $id,
                $rangeStart->toDateTimeString(),
                $rangeEnd->toDateTimeString(),
            );
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
