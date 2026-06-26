<?php

use App\Jobs\SyncHikvisionAttendanceJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Services\HikvisionService;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

test('sync hikvision attendance job syncs scheduled days', function () {
    $hikvision = Mockery::mock(HikvisionService::class);
    $hikvision->shouldReceive('syncAttendanceForScheduledDays')->once();

    (new SyncHikvisionAttendanceJob)->handle($hikvision);
});

test('sync hikvision attendance job syncs a dated day and backfills yesterday when today', function () {
    Carbon::setTestNow('2026-06-26 10:00:00', config('app.timezone'));

    $hikvision = Mockery::mock(HikvisionService::class);
    $hikvision->shouldReceive('syncAttendanceForDay')->twice();

    (new SyncHikvisionAttendanceJob('2026-06-26'))->handle($hikvision);
});

test('sync attendance skips unchanged records to avoid expensive model updates', function () {
    Carbon::setTestNow('2026-06-26 10:00:00', config('app.timezone'));

    ['company' => $company] = makeAttendanceRecordsFixtures();
    $person = HikvisionPerson::query()->create([
        'person_id' => 'perf-person-1',
        'full_name' => 'Perf Employee',
        'person_code' => '99',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Perf Employee',
        'hikvision_person_id' => $person->id,
    ]);

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
