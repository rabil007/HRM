<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SyncAttendanceRecordsFromHikvision
{
    public function syncCompany(int $companyId, CarbonInterface $from, CarbonInterface $to): int
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $workingDays = $this->resolveWorkingDays($companyId);
        $synced = 0;

        $employees = Employee::query()
            ->with('hikvisionPerson:id,person_id,full_name,person_code')
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
            ->where(function ($query) use ($employee, $personId): void {
                $this->applyEmployeeEventScope($query, $employee, $personId);
            })
            ->orderBy('occurrence_time')
            ->get(['id', 'occurrence_time', 'attendance_status', 'transaction_source', 'person_name', 'person_hikvision_id']);

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

            $source = $this->resolveSourceForDay($dayEvents);

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

    /**
     * @param  Builder<HikvisionAccessEvent>  $query
     */
    private function applyEmployeeEventScope($query, Employee $employee, string $personId): void
    {
        $aliases = $this->employeeMatchAliases($employee);

        $query->where(function ($query) use ($personId, $aliases): void {
            $hasConstraint = false;

            if ($personId !== '') {
                $query->where('person_hikvision_id', $personId);
                $hasConstraint = true;
            }

            foreach ($aliases as $alias) {
                if ($hasConstraint) {
                    $query->orWhereRaw('LOWER(person_name) = ?', [mb_strtolower($alias)]);
                } else {
                    $query->whereRaw('LOWER(person_name) = ?', [mb_strtolower($alias)]);
                    $hasConstraint = true;
                }
            }

            if (! $hasConstraint) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function employeeMatchAliases(Employee $employee): array
    {
        $aliases = [
            trim((string) $employee->name),
            trim((string) ($employee->hikvisionPerson?->full_name ?? '')),
            trim((string) ($employee->hikvisionPerson?->person_code ?? '')),
        ];

        $fullName = trim((string) ($employee->hikvisionPerson?->full_name ?? ''));

        if ($fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName) ?: [];

            if (count($parts) > 1) {
                $last = (string) end($parts);

                if (mb_strlen($last) <= 3) {
                    $aliases[] = trim(implode(' ', array_slice($parts, 0, -1)));
                }
            }
        }

        return array_values(array_unique(array_filter(
            $aliases,
            fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  Collection<int, HikvisionAccessEvent>  $dayEvents
     */
    private function resolveSourceForDay(Collection $dayEvents): ?string
    {
        if ($dayEvents->isEmpty()) {
            return null;
        }

        return $dayEvents->contains(
            fn (HikvisionAccessEvent $event): bool => $event->transaction_source === HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        )
            ? AttendanceRecord::SOURCE_MOBILE
            : AttendanceRecord::SOURCE_BIOMETRIC;
    }
}
