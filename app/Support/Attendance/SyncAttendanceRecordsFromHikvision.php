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
    private float $syncStartedAt = 0;

    public function syncCompany(int $companyId, CarbonInterface $from, CarbonInterface $to): int
    {
        $this->syncStartedAt = microtime(true);

        // #region agent log
        $this->debugLog('B', 'SyncAttendanceRecordsFromHikvision.php:syncCompany', 'syncCompany started', [
            'company_id' => $companyId,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
        ]);
        // #endregion

        $timezone = (string) config('app.timezone', 'UTC');
        $workingDays = $this->resolveWorkingDays($companyId);
        $synced = 0;

        $rangeStart = $from->copy()->timezone($timezone)->startOfDay();
        $rangeEnd = $to->copy()->timezone($timezone)->endOfDay();

        $employees = Employee::query()
            ->with('hikvisionPerson:id,person_id,full_name,person_code')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('hikvision_person_id')
            ->orderBy('id')
            ->get();

        // #region agent log
        $this->debugLog('B', 'SyncAttendanceRecordsFromHikvision.php:syncCompany', 'employees loaded', [
            'company_id' => $companyId,
            'employee_count' => $employees->count(),
        ]);
        // #endregion

        $eventsQueryStartedAt = microtime(true);
        $companyEvents = $this->loadCompanyEventsForWindow($employees, $rangeStart, $rangeEnd);
        $eventsQueryElapsedMs = (int) round((microtime(true) - $eventsQueryStartedAt) * 1000);

        /** @var Collection<string, Collection<int, HikvisionAccessEvent>> $eventsByPersonId */
        $eventsByPersonId = $companyEvents->groupBy(
            fn (HikvisionAccessEvent $event): string => (string) ($event->person_hikvision_id ?? ''),
        );

        // #region agent log
        $this->debugLog('D', 'SyncAttendanceRecordsFromHikvision.php:syncCompany', 'company events loaded', [
            'company_id' => $companyId,
            'events_count' => $companyEvents->count(),
            'query_elapsed_ms' => $eventsQueryElapsedMs,
            'distinct_person_ids' => $eventsByPersonId->keys()->filter(fn (string $key) => $key !== '')->count(),
        ]);
        // #endregion

        foreach ($employees as $employee) {
            $employeeEvents = $this->resolveEmployeeEvents($employee, $eventsByPersonId, $companyEvents);

            $synced += $this->syncEmployee(
                $employee,
                $rangeStart,
                $rangeEnd,
                $timezone,
                $workingDays,
                $employeeEvents,
            );
        }

        // #region agent log
        $this->debugLog('B', 'SyncAttendanceRecordsFromHikvision.php:syncCompany', 'syncCompany finished', [
            'company_id' => $companyId,
            'employee_count' => $employees->count(),
            'synced_records' => $synced,
            'elapsed_ms' => (int) round((microtime(true) - $this->syncStartedAt) * 1000),
        ]);
        // #endregion

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
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, HikvisionAccessEvent>
     */
    private function loadCompanyEventsForWindow(
        Collection $employees,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
    ): Collection {
        $personIds = $employees
            ->map(fn (Employee $employee): string => trim((string) ($employee->hikvisionPerson?->person_id ?? '')))
            ->filter(fn (string $personId): bool => $personId !== '')
            ->unique()
            ->values();

        $personCodes = $employees
            ->map(fn (Employee $employee): string => trim((string) ($employee->hikvisionPerson?->person_code ?? '')))
            ->filter(fn (string $personCode): bool => $personCode !== '')
            ->unique()
            ->values();

        $nameAliases = $employees
            ->flatMap(fn (Employee $employee): array => $this->employeeNameAliases($employee))
            ->map(fn (string $alias): string => mb_strtolower($alias))
            ->unique()
            ->values();

        $columns = [
            'id',
            'occurrence_time',
            'attendance_status',
            'transaction_source',
            'person_name',
            'person_hikvision_id',
        ];

        $linkedEvents = $personIds->isEmpty()
            ? collect()
            : HikvisionAccessEvent::query()
                ->accessRecords()
                ->whereBetween('occurrence_time', [$rangeStart, $rangeEnd])
                ->whereIn('person_hikvision_id', $personIds)
                ->orderBy('occurrence_time')
                ->get($columns);

        $unlinkedEvents = HikvisionAccessEvent::query()
            ->accessRecords()
            ->whereBetween('occurrence_time', [$rangeStart, $rangeEnd])
            ->where(function (Builder $query): void {
                $query->whereNull('person_hikvision_id')
                    ->orWhere('person_hikvision_id', '');
            })
            ->where(function (Builder $query) use ($nameAliases, $personCodes): void {
                $this->applyUnlinkedEventScope($query, $nameAliases, $personCodes);
            })
            ->orderBy('occurrence_time')
            ->get([...$columns, 'raw_payload']);

        return $linkedEvents
            ->merge($unlinkedEvents)
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<string, Collection<int, HikvisionAccessEvent>>  $eventsByPersonId
     * @param  Collection<int, HikvisionAccessEvent>  $companyEvents
     * @return Collection<int, HikvisionAccessEvent>
     */
    private function resolveEmployeeEvents(
        Employee $employee,
        Collection $eventsByPersonId,
        Collection $companyEvents,
    ): Collection {
        $personId = (string) ($employee->hikvisionPerson?->person_id ?? '');
        $events = $personId !== ''
            ? $eventsByPersonId->get($personId, collect())
            : collect();

        $aliases = array_map(
            mb_strtolower(...),
            $this->employeeNameAliases($employee),
        );
        $personCode = trim((string) ($employee->hikvisionPerson?->person_code ?? ''));

        if ($aliases === [] && $personCode === '') {
            return $events->values();
        }

        $unlinkedMatches = $companyEvents->filter(function (HikvisionAccessEvent $event) use ($aliases, $personCode): bool {
            if (filled($event->person_hikvision_id)) {
                return false;
            }

            $personName = mb_strtolower(trim((string) $event->person_name));

            if ($personName !== '' && in_array($personName, $aliases, true)) {
                return true;
            }

            if ($personCode === '' || $event->transaction_source !== HikvisionAccessEvent::TRANSACTION_MOBILE_APP) {
                return false;
            }

            $payload = is_array($event->raw_payload) ? $event->raw_payload : [];

            return trim((string) ($payload['personCode'] ?? '')) === $personCode;
        });

        return $events
            ->merge($unlinkedMatches)
            ->unique('id')
            ->values();
    }

    /**
     * @param  list<int>  $workingDays
     * @param  Collection<int, HikvisionAccessEvent>  $events
     */
    private function syncEmployee(
        Employee $employee,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $timezone,
        array $workingDays,
        Collection $events,
    ): int {
        $personId = (string) ($employee->hikvisionPerson?->person_id ?? '');
        $synced = 0;

        // #region agent log
        $this->debugLog('A', 'SyncAttendanceRecordsFromHikvision.php:syncEmployee', 'employee events resolved', [
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'person_id' => $personId,
            'events_count' => $events->count(),
            'date_from' => $rangeStart->toDateString(),
            'date_to' => $rangeEnd->toDateString(),
        ]);
        // #endregion

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

        /** @var Collection<string, AttendanceRecord> $existingRecords */
        $existingRecords = AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $rangeStart->toDateString())
            ->whereDate('date', '<=', $rangeEnd->toDateString())
            ->get()
            ->keyBy(fn (AttendanceRecord $record): string => $record->date->toDateString());

        $current = $rangeStart->copy();

        while ($current->lte($rangeEnd)) {
            $date = $current->toDateString();
            /** @var Collection<int, HikvisionAccessEvent> $dayEvents */
            $dayEvents = $eventsByDate->get($date, collect());

            $existing = $existingRecords->get($date);

            if ($existing !== null && $existing->source === AttendanceRecord::SOURCE_MANUAL) {
                $current->addDay();

                continue;
            }

            ['clockIn' => $clockIn, 'clockOut' => $clockOut] = $this->resolveClockTimesForDay($dayEvents);

            $source = $this->resolveSourceForDay($dayEvents);

            $attributes = [
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'hours_worked' => AttendanceRecord::calculateHoursWorked($clockIn, $clockOut),
                'overtime_hours' => 0,
                'late_minutes' => 0,
                'source' => $source,
                'status' => $this->resolveStatusForDay($date, $clockIn, $clockOut, $workingDays, $approvedLeaves),
                'notes' => null,
            ];

            if ($existing !== null) {
                if ($this->attendanceAttributesMatch($existing, $attributes)) {
                    $current->addDay();

                    continue;
                }

                AttendanceRecord::query()->whereKey($existing->id)->update($attributes);
            } else {
                AttendanceRecord::query()->create([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'date' => $date,
                    ...$attributes,
                ]);
            }

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
     * @param  Collection<int, string>  $nameAliases
     * @param  Collection<int, string>  $personCodes
     */
    private function applyUnlinkedEventScope(Builder $query, Collection $nameAliases, Collection $personCodes): void
    {
        $hasConstraint = false;

        foreach ($nameAliases as $alias) {
            if ($hasConstraint) {
                $query->orWhereRaw('LOWER(person_name) = ?', [$alias]);
            } else {
                $query->whereRaw('LOWER(person_name) = ?', [$alias]);
                $hasConstraint = true;
            }
        }

        foreach ($personCodes as $personCode) {
            $personCodeConstraint = function (Builder $mobileQuery) use ($personCode): void {
                $mobileQuery
                    ->where('transaction_source', HikvisionAccessEvent::TRANSACTION_MOBILE_APP)
                    ->where('raw_payload->personCode', $personCode);
            };

            if ($hasConstraint) {
                $query->orWhere($personCodeConstraint);
            } else {
                $query->where($personCodeConstraint);
                $hasConstraint = true;
            }
        }

        if (! $hasConstraint) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @return list<string>
     */
    private function employeeNameAliases(Employee $employee): array
    {
        $aliases = [
            trim((string) $employee->name),
            trim((string) ($employee->hikvisionPerson?->full_name ?? '')),
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
     * @return array{clockIn: ?\DateTimeInterface, clockOut: ?\DateTimeInterface}
     */
    private function resolveClockTimesForDay(Collection $dayEvents): array
    {
        $dayEventCount = $dayEvents->count();

        if ($dayEventCount > 100) {
            // #region agent log
            $this->debugLog('C', 'SyncAttendanceRecordsFromHikvision.php:resolveClockTimesForDay', 'large dayEvents collection', [
                'day_events_count' => $dayEventCount,
            ]);
            // #endregion
        }

        $clockIn = null;
        $clockInTimestamp = null;
        $clockOut = null;
        $clockOutTimestamp = null;
        $checkInCount = 0;
        $lastCheckIn = null;
        $lastCheckInTimestamp = null;

        foreach ($dayEvents as $event) {
            $occurrenceTime = $event->occurrence_time;

            if ($occurrenceTime === null) {
                continue;
            }

            $timestamp = $occurrenceTime->getTimestamp();

            if ($event->attendance_status === HikvisionAccessEvent::ATTENDANCE_CHECK_IN) {
                $checkInCount++;

                if ($lastCheckInTimestamp === null || $timestamp > $lastCheckInTimestamp) {
                    $lastCheckInTimestamp = $timestamp;
                    $lastCheckIn = $occurrenceTime;
                }

                if ($clockInTimestamp === null || $timestamp < $clockInTimestamp) {
                    $clockInTimestamp = $timestamp;
                    $clockIn = $occurrenceTime;
                }
            }

            if ($event->attendance_status === HikvisionAccessEvent::ATTENDANCE_CHECK_OUT
                && ($clockOutTimestamp === null || $timestamp > $clockOutTimestamp)) {
                $clockOutTimestamp = $timestamp;
                $clockOut = $occurrenceTime;
            }
        }

        if ($clockOut === null && $checkInCount >= 2) {
            $clockOut = $lastCheckIn;
        }

        return [
            'clockIn' => $clockIn,
            'clockOut' => $clockOut,
        ];
    }

    /**
     * @param  array{
     *     clock_in: ?\DateTimeInterface,
     *     clock_out: ?\DateTimeInterface,
     *     hours_worked: float|string|null,
     *     overtime_hours: float|int,
     *     late_minutes: int,
     *     source: ?string,
     *     status: string,
     *     notes: ?string
     * }  $attributes
     */
    private function attendanceAttributesMatch(AttendanceRecord $existing, array $attributes): bool
    {
        return $existing->clock_in?->getTimestamp() === $attributes['clock_in']?->getTimestamp()
            && $existing->clock_out?->getTimestamp() === $attributes['clock_out']?->getTimestamp()
            && (float) $existing->hours_worked === (float) $attributes['hours_worked']
            && (float) $existing->overtime_hours === (float) $attributes['overtime_hours']
            && (int) $existing->late_minutes === (int) $attributes['late_minutes']
            && $existing->source === $attributes['source']
            && $existing->status === $attributes['status']
            && $existing->notes === $attributes['notes'];
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function debugLog(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        // #region agent log
        file_put_contents(
            '/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-906348.log',
            json_encode([
                'sessionId' => '906348',
                'hypothesisId' => $hypothesisId,
                'location' => $location,
                'message' => $message,
                'data' => $data,
                'timestamp' => (int) round(microtime(true) * 1000),
                'runId' => 'post-fix',
            ], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND
        );
        // #endregion
    }
}
