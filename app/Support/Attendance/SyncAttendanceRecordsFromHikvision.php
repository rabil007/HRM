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
            ->with('hikvisionPerson:id,person_id,full_name')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('hikvision_person_id')
            ->get();

        foreach ($employees as $employee) {
            $synced += $this->syncEmployee($employee, $from, $to, $timezone, $workingDays);
        }

        $this->logUnmatchedCheckoutEvents($companyId, $employees, $from, $to, $timezone);

        return $synced;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function logUnmatchedCheckoutEvents(
        int $companyId,
        Collection $employees,
        CarbonInterface $from,
        CarbonInterface $to,
        string $timezone,
    ): void {
        $rangeStart = $from->copy()->timezone($timezone)->startOfDay();
        $rangeEnd = $to->copy()->timezone($timezone)->endOfDay();

        $checkoutEvents = HikvisionAccessEvent::query()
            ->accessRecords()
            ->whereBetween('occurrence_time', [$rangeStart, $rangeEnd])
            ->where('attendance_status', HikvisionAccessEvent::ATTENDANCE_CHECK_OUT)
            ->get(['id', 'person_name', 'person_hikvision_id', 'occurrence_time', 'transaction_source']);

        $matchedNames = [];
        $matchedPersonIds = [];

        foreach ($employees as $employee) {
            $matchedNames[] = $employee->name;
            $personId = (string) ($employee->hikvisionPerson?->person_id ?? '');

            if ($personId !== '') {
                $matchedPersonIds[] = $personId;
            }
        }

        $unmatched = $checkoutEvents->filter(function (HikvisionAccessEvent $event) use ($matchedNames, $matchedPersonIds): bool {
            $personId = (string) ($event->person_hikvision_id ?? '');
            $personName = (string) ($event->person_name ?? '');

            if ($personId !== '' && in_array($personId, $matchedPersonIds, true)) {
                return false;
            }

            return ! in_array($personName, $matchedNames, true);
        })->map(fn (HikvisionAccessEvent $event): array => [
            'event_id' => $event->id,
            'person_name' => $event->person_name,
            'person_hikvision_id' => $event->person_hikvision_id,
            'occurrence_time' => $event->occurrence_time?->toIso8601String(),
            'transaction_source' => $event->transaction_source,
        ])->values()->all();

        $this->debugLog('E', 'SyncAttendanceRecordsFromHikvision::logUnmatchedCheckoutEvents', 'Checkout events not matched to any linked employee', [
            'company_id' => $companyId,
            'date_from' => $rangeStart->toDateString(),
            'date_to' => $rangeEnd->toDateString(),
            'total_checkout_events' => $checkoutEvents->count(),
            'unmatched_count' => count($unmatched),
            'unmatched' => $unmatched,
            'linked_employee_names' => $matchedNames,
        ]);
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

            $source = $this->resolveSourceForDay($dayEvents);

            $this->debugLog('A', 'SyncAttendanceRecordsFromHikvision::syncEmployee', 'Daily sync snapshot', [
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'hikvision_person_id' => $personId,
                'hikvision_full_name' => $employee->hikvisionPerson?->full_name,
                'date' => $date,
                'day_event_count' => $dayEvents->count(),
                'check_in_events' => $dayEvents
                    ->filter(fn (HikvisionAccessEvent $event): bool => $event->attendance_status === HikvisionAccessEvent::ATTENDANCE_CHECK_IN)
                    ->map(fn (HikvisionAccessEvent $event): array => [
                        'person_name' => $event->person_name,
                        'person_hikvision_id' => $event->person_hikvision_id,
                        'transaction_source' => $event->transaction_source,
                    ])->values()->all(),
                'check_out_events' => $dayEvents
                    ->filter(fn (HikvisionAccessEvent $event): bool => $event->attendance_status === HikvisionAccessEvent::ATTENDANCE_CHECK_OUT)
                    ->map(fn (HikvisionAccessEvent $event): array => [
                        'person_name' => $event->person_name,
                        'person_hikvision_id' => $event->person_hikvision_id,
                        'transaction_source' => $event->transaction_source,
                    ])->values()->all(),
                'resolved_clock_in' => $clockIn?->toIso8601String(),
                'resolved_clock_out' => $clockOut?->toIso8601String(),
                'resolved_source' => $source,
            ]);

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
     * @param  Collection<int, HikvisionAccessEvent>  $dayEvents
     */
    private function resolveSourceForDay(Collection $dayEvents): string
    {
        if ($dayEvents->isEmpty()) {
            return AttendanceRecord::SOURCE_WEB;
        }

        return $dayEvents->contains(
            fn (HikvisionAccessEvent $event): bool => $event->transaction_source === HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        )
            ? AttendanceRecord::SOURCE_MOBILE
            : AttendanceRecord::SOURCE_BIOMETRIC;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function debugLog(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        // #region agent log
        try {
            $logPath = base_path('.cursor/debug-c72436.log');
            $directory = dirname($logPath);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents(
                $logPath,
                json_encode([
                    'sessionId' => 'c72436',
                    'hypothesisId' => $hypothesisId,
                    'location' => $location,
                    'message' => $message,
                    'data' => $data,
                    'timestamp' => (int) round(microtime(true) * 1000),
                ], JSON_UNESCAPED_UNICODE)."\n",
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable) {
            // Never break attendance sync when debug logging fails.
        }
        // #endregion
    }
}
