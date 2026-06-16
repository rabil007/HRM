<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class SyncAttendanceRecordsFromHikvision
{
    public function syncCompany(int $companyId, CarbonInterface $from, CarbonInterface $to): int
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $workingDays = $this->resolveWorkingDays($companyId);
        $synced = 0;

        $employees = Employee::query()
            ->with('hikvisionPerson:id,person_id')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('hikvision_person_id')
            ->get();

        foreach ($employees as $employee) {
            $synced += $this->syncEmployee($employee, $from, $to, $timezone, $workingDays);
        }

        return $synced;
    }

    /**
     * @return list<int>
     */
    private function resolveWorkingDays(int $companyId): array
    {
        $workingDays = Company::query()
            ->whereKey($companyId)
            ->value('working_days');

        if (! is_array($workingDays) || $workingDays === []) {
            return [1, 2, 3, 4, 5];
        }

        return array_map(intval(...), $workingDays);
    }

    /**
     * @param  list<int>  $workingDays
     */
    private function syncEmployee(
        Employee $employee,
        CarbonInterface $from,
        CarbonInterface $to,
        string $timezone,
        array $workingDays,
    ): int {
        $personId = (string) ($employee->hikvisionPerson?->person_id ?? '');
        $synced = 0;

        $rangeStart = $from->copy()->timezone($timezone)->startOfDay();
        $rangeEnd = $to->copy()->timezone($timezone)->endOfDay();

        $events = HikvisionAccessEvent::query()
            ->accessRecords()
            ->whereBetween('occurrence_time', [$rangeStart, $rangeEnd])
            ->where(function ($query) use ($personId, $employee): void {
                if ($personId !== '') {
                    $query->where('person_hikvision_id', $personId);
                }

                $query->orWhere('person_name', $employee->name);
            })
            ->orderBy('occurrence_time')
            ->get(['id', 'occurrence_time', 'attendance_status', 'transaction_source']);

        /** @var Collection<string, Collection<int, HikvisionAccessEvent>> $eventsByDate */
        $eventsByDate = $events->groupBy(
            fn (HikvisionAccessEvent $event): string => $event->occurrence_time
                ?->timezone($timezone)
                ->toDateString() ?? '',
        );

        $approvedLeaves = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $rangeEnd->toDateString())
            ->whereDate('end_date', '>=', $rangeStart->toDateString())
            ->get(['start_date', 'end_date']);

        $current = $rangeStart->copy();

        while ($current->lte($rangeEnd)) {
            $date = $current->toDateString();
            /** @var Collection<int, HikvisionAccessEvent> $dayEvents */
            $dayEvents = $eventsByDate->get($date, collect());

            $existing = AttendanceRecord::query()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->first();

            if ($existing !== null && $existing->source === AttendanceRecord::SOURCE_MANUAL) {
                $current->addDay();

                continue;
            }

            $clockIn = $dayEvents
                ->filter(fn (HikvisionAccessEvent $event): bool => $event->attendance_status === HikvisionAccessEvent::ATTENDANCE_CHECK_IN)
                ->sortBy('occurrence_time')
                ->first()?->occurrence_time;

            $clockOut = $dayEvents
                ->filter(fn (HikvisionAccessEvent $event): bool => $event->attendance_status === HikvisionAccessEvent::ATTENDANCE_CHECK_OUT)
                ->sortByDesc('occurrence_time')
                ->first()?->occurrence_time;

            $source = $dayEvents->contains(
                fn (HikvisionAccessEvent $event): bool => $event->transaction_source === HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
            )
                ? AttendanceRecord::SOURCE_MOBILE
                : AttendanceRecord::SOURCE_BIOMETRIC;

            AttendanceRecord::query()->updateOrCreate(
                [
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'date' => $date,
                ],
                [
                    'clock_in' => $clockIn,
                    'clock_out' => $clockOut,
                    'hours_worked' => AttendanceRecord::calculateHoursWorked($clockIn, $clockOut),
                    'overtime_hours' => 0,
                    'late_minutes' => 0,
                    'source' => $source,
                    'status' => $this->resolveStatusForDay($date, $clockIn, $clockOut, $workingDays, $approvedLeaves),
                    'notes' => null,
                ],
            );

            $synced++;
            $current->addDay();
        }

        return $synced;
    }

    /**
     * @param  list<int>  $workingDays
     * @param  Collection<int, LeaveRequest>  $approvedLeaves
     */
    private function resolveStatusForDay(
        string $date,
        ?\DateTimeInterface $clockIn,
        ?\DateTimeInterface $clockOut,
        array $workingDays,
        Collection $approvedLeaves,
    ): string {
        if ($clockIn !== null || $clockOut !== null) {
            return AttendanceRecord::STATUS_PRESENT;
        }

        if (! in_array(Carbon::parse($date)->isoWeekday(), $workingDays, true)) {
            return AttendanceRecord::STATUS_WEEKEND;
        }

        foreach ($approvedLeaves as $leave) {
            $start = $leave->start_date?->toDateString();
            $end = $leave->end_date?->toDateString();

            if ($start !== null && $end !== null && $date >= $start && $date <= $end) {
                return AttendanceRecord::STATUS_HOLIDAY;
            }
        }

        return AttendanceRecord::STATUS_ABSENT;
    }
}
