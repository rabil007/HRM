<?php

namespace App\Support\Attendance;

use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class TodayAttendanceTimeline
{
    /**
     * @return array{
     *     date: string,
     *     timezone: string,
     *     window_start: string,
     *     window_end: string,
     *     events: list<array{
     *         time: string,
     *         status: string,
     *         device_name: string|null,
     *         transaction_source: string|null,
     *     }>,
     *     summary: array{
     *         clock_in: string|null,
     *         clock_out: string|null,
     *         is_complete: bool,
     *         is_on_leave: bool,
     *         status: string,
     *         event_count: int,
     *         elapsed_minutes: int|null,
     *     },
     * }|null
     */
    public function forEmployee(int $companyId, ?int $employeeId): ?array
    {
        if ($employeeId === null) {
            return null;
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->with('hikvisionPerson:id,person_id')
            ->first();

        if ($employee === null || $employee->hikvisionPerson === null) {
            return null;
        }

        $personHikvisionId = trim((string) ($employee->hikvisionPerson->person_id ?? ''));

        if ($personHikvisionId === '') {
            return null;
        }

        $timezone = (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
        $now = now($timezone);
        $today = $now->toDateString();
        $rangeStart = $now->copy()->startOfDay();
        $rangeEnd = $now->copy()->endOfDay();

        /** @var Collection<int, HikvisionAccessEvent> $events */
        $events = HikvisionAccessEvent::query()
            ->accessRecords()
            ->forCompany($companyId)
            ->whereBetween('occurrence_time', [$rangeStart, $rangeEnd])
            ->where('person_hikvision_id', $personHikvisionId)
            ->whereIn('attendance_status', [
                HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
                HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
            ])
            ->orderBy('occurrence_time')
            ->get(['id', 'occurrence_time', 'attendance_status', 'device_name', 'transaction_source']);

        $serializedEvents = $events
            ->map(fn (HikvisionAccessEvent $event): array => [
                'time' => $event->occurrence_time?->timezone($timezone)->format('H:i') ?? '',
                'status' => (string) ($event->attendance_status ?? ''),
                'device_name' => $event->device_name,
                'transaction_source' => $event->transaction_source,
            ])
            ->filter(fn (array $event): bool => $event['time'] !== '' && in_array($event['status'], [
                HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
                HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
            ], true))
            ->values()
            ->all();

        $clockTimes = $this->resolveClockTimes($events);
        $clockIn = $clockTimes['clockIn']?->timezone($timezone)->format('H:i');
        $clockOut = $clockTimes['clockOut']?->timezone($timezone)->format('H:i');
        $isComplete = $clockOut !== null;

        $isOnLeave = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->exists();

        $status = $this->resolveStatus(
            isOnLeave: $isOnLeave,
            hasEvents: $serializedEvents !== [],
            clockIn: $clockIn,
            isComplete: $isComplete,
        );

        $elapsedMinutes = $this->elapsedMinutes(
            clockIn: $clockTimes['clockIn'],
            clockOut: $clockTimes['clockOut'],
            now: $now,
            timezone: $timezone,
        );

        [$windowStart, $windowEnd] = $this->resolveWindow(
            events: $serializedEvents,
            clockIn: $clockIn,
            clockOut: $clockOut,
            now: $now,
            isComplete: $isComplete,
        );

        return [
            'date' => $today,
            'timezone' => $timezone,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'events' => $serializedEvents,
            'summary' => [
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'is_complete' => $isComplete,
                'is_on_leave' => $isOnLeave,
                'status' => $status,
                'event_count' => count($serializedEvents),
                'elapsed_minutes' => $elapsedMinutes,
            ],
        ];
    }

    /**
     * @param  Collection<int, HikvisionAccessEvent>  $dayEvents
     * @return array{clockIn: ?CarbonInterface, clockOut: ?CarbonInterface}
     */
    private function resolveClockTimes(Collection $dayEvents): array
    {
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

    private function resolveStatus(
        bool $isOnLeave,
        bool $hasEvents,
        ?string $clockIn,
        bool $isComplete,
    ): string {
        if ($isOnLeave && ! $hasEvents) {
            return 'on_leave';
        }

        if ($isComplete) {
            return 'checked_out';
        }

        if ($clockIn !== null) {
            return 'checked_in';
        }

        if ($hasEvents) {
            return 'partial';
        }

        return 'no_activity';
    }

    private function elapsedMinutes(
        ?CarbonInterface $clockIn,
        ?CarbonInterface $clockOut,
        CarbonInterface $now,
        string $timezone,
    ): ?int {
        if ($clockIn === null) {
            return null;
        }

        $end = $clockOut?->timezone($timezone) ?? $now;

        return max(0, (int) $clockIn->timezone($timezone)->diffInMinutes($end));
    }

    /**
     * @param  list<array{time: string, status: string, device_name: string|null, transaction_source: string|null}>  $events
     * @return array{0: string, 1: string}
     */
    private function resolveWindow(
        array $events,
        ?string $clockIn,
        ?string $clockOut,
        CarbonInterface $now,
        bool $isComplete,
    ): array {
        $times = collect($events)->pluck('time')->filter()->values();

        if ($clockIn !== null) {
            $times->push($clockIn);
        }

        if ($clockOut !== null) {
            $times->push($clockOut);
        }

        if (! $isComplete) {
            $times->push($now->format('H:i'));
        }

        $defaultStart = 9 * 60;
        $defaultEnd = 18 * 60;

        if ($times->isEmpty()) {
            return ['09:00', '18:00'];
        }

        $minutes = $times
            ->map(fn (string $time): int => $this->timeToMinutes($time))
            ->sort()
            ->values();

        $start = max(0, min($defaultStart, $minutes->first() - 30));
        $end = min((24 * 60) - 1, max($defaultEnd, $minutes->last() + 30));

        if ($end - $start < 4 * 60) {
            $end = min((24 * 60) - 1, $start + (4 * 60));
        }

        return [
            $this->minutesToTime($start),
            $this->minutesToTime($end),
        ];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hours * 60) + (int) $minutes;
    }

    private function minutesToTime(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }
}
