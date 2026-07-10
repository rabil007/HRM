<?php

use App\Jobs\SyncCompanyHikvisionAttendanceJob;
use App\Jobs\SyncHikvisionAttendanceJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Support\Attendance\DispatchHikvisionAttendanceSync;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    Carbon::setTestNow();
});

test('sync hikvision attendance job dispatches per-company jobs for yesterday when scheduled', function () {
    Carbon::setTestNow('2026-06-26 10:00:00', config('app.timezone'));

    $person = HikvisionPerson::query()->create([
        'person_id' => 'dispatch-person-1',
        'full_name' => 'Dispatch Employee',
        'person_code' => '11',
    ]);

    $employee = Employee::factory()->create([
        'status' => 'active',
        'hikvision_person_id' => $person->id,
    ]);

    Queue::fake();

    (new SyncHikvisionAttendanceJob)->handle(app(DispatchHikvisionAttendanceSync::class));

    Queue::assertPushed(SyncCompanyHikvisionAttendanceJob::class, function (SyncCompanyHikvisionAttendanceJob $job) use ($employee): bool {
        return $job->companyId === (int) $employee->company_id
            && $job->from === '2026-06-25 00:00:00'
            && $job->to === '2026-06-25 23:59:59';
    });
});

test('sync hikvision attendance job dispatches dated day and backfills yesterday when today', function () {
    Carbon::setTestNow('2026-06-26 10:00:00', config('app.timezone'));

    $person = HikvisionPerson::query()->create([
        'person_id' => 'dispatch-person-2',
        'full_name' => 'Dispatch Employee Two',
        'person_code' => '12',
    ]);

    Employee::factory()->create([
        'status' => 'active',
        'hikvision_person_id' => $person->id,
    ]);

    Queue::fake();

    (new SyncHikvisionAttendanceJob('2026-06-26'))->handle(app(DispatchHikvisionAttendanceSync::class));

    Queue::assertPushed(SyncCompanyHikvisionAttendanceJob::class, function (SyncCompanyHikvisionAttendanceJob $job): bool {
        return $job->from === '2026-06-26 00:00:00'
            && $job->to === '2026-06-26 23:59:59';
    });

    Queue::assertPushed(SyncCompanyHikvisionAttendanceJob::class, function (SyncCompanyHikvisionAttendanceJob $job): bool {
        return $job->from === '2026-06-25 00:00:00'
            && $job->to === '2026-06-25 23:59:59';
    });

    Queue::assertPushed(SyncCompanyHikvisionAttendanceJob::class, 2);
});

test('dispatch hikvision attendance sync dispatches no jobs when no linked active employees', function () {
    Queue::fake();

    $dispatched = app(DispatchHikvisionAttendanceSync::class)->dispatchForWindow(
        Carbon::parse('2026-06-25 00:00:00', config('app.timezone')),
        Carbon::parse('2026-06-25 23:59:59', config('app.timezone')),
    );

    expect($dispatched)->toBe(0);
    Queue::assertNothingPushed();
});

test('dispatch hikvision attendance sync dispatches one job per company', function () {
    $personOne = HikvisionPerson::query()->create([
        'person_id' => 'dispatch-person-3',
        'full_name' => 'Company One Employee',
        'person_code' => '13',
    ]);

    $personTwo = HikvisionPerson::query()->create([
        'person_id' => 'dispatch-person-4',
        'full_name' => 'Company Two Employee',
        'person_code' => '14',
    ]);

    $employeeOne = Employee::factory()->create([
        'status' => 'active',
        'hikvision_person_id' => $personOne->id,
    ]);

    $employeeTwo = Employee::factory()->create([
        'status' => 'active',
        'hikvision_person_id' => $personTwo->id,
    ]);

    Queue::fake();

    $dispatched = app(DispatchHikvisionAttendanceSync::class)->dispatchForWindow(
        Carbon::parse('2026-06-25 00:00:00', config('app.timezone')),
        Carbon::parse('2026-06-25 23:59:59', config('app.timezone')),
    );

    expect($dispatched)->toBe(2);

    Queue::assertPushed(SyncCompanyHikvisionAttendanceJob::class, function (SyncCompanyHikvisionAttendanceJob $job) use ($employeeOne): bool {
        return $job->companyId === (int) $employeeOne->company_id;
    });

    Queue::assertPushed(SyncCompanyHikvisionAttendanceJob::class, function (SyncCompanyHikvisionAttendanceJob $job) use ($employeeTwo): bool {
        return $job->companyId === (int) $employeeTwo->company_id;
    });
});

test('sync attendance skips unchanged records to avoid expensive model updates', function () {
    Carbon::setTestNow('2026-06-26 10:00:00', config('app.timezone'));

    $person = HikvisionPerson::query()->create([
        'person_id' => 'perf-person-1',
        'full_name' => 'Perf Employee',
        'person_code' => '99',
    ]);

    $employee = Employee::factory()->create([
        'status' => 'active',
        'name' => 'Perf Employee',
        'hikvision_person_id' => $person->id,
    ]);

    $company = $employee->company;

    HikvisionAccessEvent::query()->create([
        'system_id' => 'perf:checkin',
        'msg_type' => 'webhook/event/110013',
        'occurrence_time' => '2026-06-26 08:30:00',
        'person_name' => 'Perf Employee',
        'person_hikvision_id' => 'perf-person-1',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'raw_payload' => ['name' => 'Perf Employee'],
        'fetched_at' => '2026-06-26 08:31:00',
    ]);

    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-26', config('app.timezone'));

    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    DB::enableQueryLog();
    DB::flushQueryLog();

    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $updateQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'update'));

    expect($updateQueries)->toBeEmpty();

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-26')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT);
});

test('sync company preloads leave and attendance queries once per company', function () {
    Carbon::setTestNow('2026-06-26 10:00:00', config('app.timezone'));

    $personOne = HikvisionPerson::query()->create([
        'person_id' => 'batch-person-1',
        'full_name' => 'Batch Employee One',
        'person_code' => '21',
    ]);

    $personTwo = HikvisionPerson::query()->create([
        'person_id' => 'batch-person-2',
        'full_name' => 'Batch Employee Two',
        'person_code' => '22',
    ]);

    $employeeOne = Employee::factory()->create([
        'status' => 'active',
        'name' => 'Batch Employee One',
        'hikvision_person_id' => $personOne->id,
    ]);

    Employee::factory()->for($employeeOne->company)->create([
        'status' => 'active',
        'name' => 'Batch Employee Two',
        'hikvision_person_id' => $personTwo->id,
    ]);

    $company = $employeeOne->company;

    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-26', config('app.timezone'));

    DB::enableQueryLog();
    DB::flushQueryLog();

    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $leaveSelectQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "leave_requests"')
            || str_contains(strtolower($query['query']), 'from `leave_requests`'));

    $attendanceSelectQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "attendance_records"')
            || str_contains(strtolower($query['query']), 'from `attendance_records`'));

    expect($leaveSelectQueries)->toHaveCount(1)
        ->and($attendanceSelectQueries)->toHaveCount(1);
});
