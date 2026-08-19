<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Jobs\SyncCompanyHikvisionAttendanceJob;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\HikvisionReconciliation;
use App\Models\HikvisionSetting;
use App\Models\JobRun;
use App\Services\HikvisionService;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use App\Support\Hikvision\HikvisionFetchOrigin;
use App\Support\Queue\JobRunRecorder;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

afterEach(function () {
    Carbon::setTestNow();
});

test('before configured reconciliation time yesterday is not dispatched early', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 17:55:00', 'Asia/Dubai'));

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    expect(HikvisionAccessEventsFetchSchedule::dueReconciliations())->toBeEmpty();

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('No Hikvision companies are due for scheduled fetch.');

    Queue::assertNothingPushed();
});

test('at or after configured time unresolved yesterday is dispatched', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 1]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, function (FetchHikvisionAccessEventsJob $job) use ($settings): bool {
        return $job->hikvisionSettingId === $settings->id
            && $job->date === '2026-06-25'
            && $job->origin === HikvisionFetchOrigin::ScheduledReconciliation;
    });
});

test('if exact configured minute was missed next scheduler run still dispatches', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:42:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 1]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, function (FetchHikvisionAccessEventsJob $job) use ($settings): bool {
        return $job->hikvisionSettingId === $settings->id
            && $job->date === '2026-06-25';
    });
});

test('already reconciled dates are not repeatedly dispatched', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:01:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 3]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    HikvisionReconciliation::markCompleted($company->id, '2026-06-23', HikvisionFetchOrigin::CatchUp);
    HikvisionReconciliation::markCompleted($company->id, '2026-06-24', HikvisionFetchOrigin::CatchUp);
    HikvisionReconciliation::markCompleted($company->id, '2026-06-25', HikvisionFetchOrigin::ScheduledReconciliation);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('No Hikvision companies are due for scheduled fetch.');

    Queue::assertNothingPushed();
});

test('missed date from two days ago is automatically caught up', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 2]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // Reconcile 2026-06-25, but 2026-06-24 is not reconciled
    HikvisionReconciliation::markCompleted(
        $company->id,
        '2026-06-25',
        HikvisionFetchOrigin::ScheduledReconciliation,
    );

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, function (FetchHikvisionAccessEventsJob $job) use ($settings): bool {
        return $job->hikvisionSettingId === $settings->id
            && $job->date === '2026-06-24'
            && $job->origin === HikvisionFetchOrigin::CatchUp;
    });
});

test('lookback does not exceed configured recovery window', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 2]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // Reconcile 2 days ago and yesterday
    HikvisionReconciliation::markCompleted($company->id, '2026-06-24', HikvisionFetchOrigin::ScheduledReconciliation);
    HikvisionReconciliation::markCompleted($company->id, '2026-06-25', HikvisionFetchOrigin::ScheduledReconciliation);

    // 3 days ago (2026-06-23) is unreconciled, but lookback is 2 days
    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('No Hikvision companies are due for scheduled fetch.');

    Queue::assertNothingPushed();
});

test('company timezone is respected for schedule time', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:30:00', 'Asia/Dubai'));

    $companyDubai = hikvisionTestCompany();
    $companyDubai->update(['timezone' => 'Asia/Dubai']);
    $settingsDubai = configuredHikvisionSettings($companyDubai->id);
    $settingsDubai->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $companyNY = additionalHikvisionTestCompany($companyDubai, 'ny-company');
    $companyNY->update(['timezone' => 'America/New_York']);
    $settingsNY = configuredHikvisionSettings($companyNY->id);
    $settingsNY->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $due = HikvisionAccessEventsFetchSchedule::dueReconciliations();

    expect($due->pluck('setting.id')->all())->toContain($settingsDubai->id)
        ->and($due->pluck('setting.id')->all())->not->toContain($settingsNY->id);
});

test('one company reconciliation state does not affect another company', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 1]);

    $companyA = hikvisionTestCompany();
    $companyA->update(['timezone' => 'Asia/Dubai']);
    $settingsA = configuredHikvisionSettings($companyA->id);
    $settingsA->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $companyB = additionalHikvisionTestCompany($companyA, 'b-reconcile');
    $companyB->update(['timezone' => 'Asia/Dubai']);
    $settingsB = configuredHikvisionSettings($companyB->id);
    $settingsB->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // Company A is reconciled, Company B is not
    HikvisionReconciliation::markCompleted($companyA->id, '2026-06-25', HikvisionFetchOrigin::ScheduledReconciliation);

    $due = HikvisionAccessEventsFetchSchedule::dueReconciliations();

    expect($due->pluck('setting.id')->all())->not->toContain($settingsA->id)
        ->and($due->pluck('setting.id')->all())->toContain($settingsB->id);
});

test('stale processing status does not permanently block reconciliation', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 1]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
        'events_fetch_status' => HikvisionSetting::EVENTS_FETCH_RUNNING,
        'events_fetch_started_at' => now('Asia/Dubai')->subMinutes(10),
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);
    expect($settings->fresh()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);
});

test('genuinely active fetch is not duplicated', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 1]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
        'events_fetch_status' => HikvisionSetting::EVENTS_FETCH_RUNNING,
        'events_fetch_started_at' => now('Asia/Dubai')->subMinute(),
    ]);

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched 0 Hikvision access events fetch job(s).');

    Queue::assertNothingPushed();
});

test('manual fetch is recorded as manual trigger in job run', function () {
    $uuid = (string) Str::uuid();
    $company = hikvisionTestCompany();
    $settings = configuredHikvisionSettings($company->id);

    $jobInstance = new FetchHikvisionAccessEventsJob($settings->id, '2026-06-25', HikvisionFetchOrigin::Manual);

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => FetchHikvisionAccessEventsJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => FetchHikvisionAccessEventsJob::class,
            'command' => serialize($jobInstance),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    $recorder = app(JobRunRecorder::class);
    $recorder->recordQueueStarting(new JobProcessing('database', $queueJob));

    $run = JobRun::query()->where('correlation_id', $uuid)->first();

    expect($run)->not->toBeNull()
        ->and($run->trigger)->toBe(JobRun::TRIGGER_MANUAL)
        ->and($run->context['fetch_origin'])->toBe('manual')
        ->and($run->context['date'])->toBe('2026-06-25');
});

test('scheduled same-day fetch is recorded as schedule trigger not manual', function () {
    $uuid = (string) Str::uuid();
    $company = hikvisionTestCompany();
    $settings = configuredHikvisionSettings($company->id);

    $jobInstance = new FetchHikvisionAccessEventsJob($settings->id, '2026-06-26', HikvisionFetchOrigin::ScheduledToday);

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => FetchHikvisionAccessEventsJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => FetchHikvisionAccessEventsJob::class,
            'command' => serialize($jobInstance),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    $recorder = app(JobRunRecorder::class);
    $recorder->recordQueueStarting(new JobProcessing('database', $queueJob));

    $run = JobRun::query()->where('correlation_id', $uuid)->first();

    expect($run)->not->toBeNull()
        ->and($run->trigger)->toBe(JobRun::TRIGGER_SCHEDULE)
        ->and($run->context['fetch_origin'])->toBe('scheduled_today')
        ->and($run->context['date'])->toBe('2026-06-26');
});

test('scheduled previous-day reconciliation and catch-up jobs record correct origin and safe context', function () {
    $company = hikvisionTestCompany();
    $settings = configuredHikvisionSettings($company->id);

    $uuid = (string) Str::uuid();
    $jobRun = JobRun::query()->create([
        'correlation_id' => $uuid,
        'type' => JobRun::TYPE_QUEUE,
        'name' => 'FetchHikvisionAccessEventsJob',
        'status' => JobRun::STATUS_RUNNING,
        'trigger' => JobRun::TRIGGER_SCHEDULE,
        'started_at' => now(),
    ]);

    $hikvision = Mockery::mock(HikvisionService::class);
    $hikvision->shouldReceive('fetchAccessEvents')
        ->once()
        ->andReturn([
            'fetched_count' => 15,
            'device_count' => 10,
            'mobile_count' => 5,
            'message' => 'Fetched 15 access record(s) for 2026-06-24 (10 device, 5 mobile app).',
        ]);

    Queue::fake();

    $mockJob = Mockery::mock(Job::class);
    $mockJob->shouldReceive('uuid')->andReturn($uuid);

    $job = new FetchHikvisionAccessEventsJob($settings->id, '2026-06-24', HikvisionFetchOrigin::CatchUp);
    $job->setJob($mockJob);
    $job->handle($hikvision);

    $run = $jobRun->fresh();
    expect($run->context)->toBe([
        'fetched_count' => 15,
        'device_count' => 10,
        'mobile_count' => 5,
        'date' => '2026-06-24',
        'company_id' => $company->id,
        'fetch_origin' => 'catch_up',
    ]);

    expect(HikvisionReconciliation::isReconciled($company->id, '2026-06-24'))->toBeTrue();
    $reconciliation = HikvisionReconciliation::query()
        ->forCompany($company->id)
        ->whereDate('target_date', '2026-06-24')
        ->first();

    expect($reconciliation)->not->toBeNull()
        ->and($reconciliation->fetch_origin)->toBe('catch_up')
        ->and($reconciliation->events_fetched_count)->toBe(15)
        ->and($reconciliation->device_events_count)->toBe(10)
        ->and($reconciliation->mobile_events_count)->toBe(5);
});

test('re-fetching the same Hikvision date is idempotent and does not duplicate events', function () {
    $company = hikvisionTestCompany();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'p-idemp-1',
        'full_name' => 'Idempotent Employee',
    ]);

    $employee = Employee::factory()->for($company)->create([
        'status' => 'active',
        'name' => 'Idempotent Employee',
        'hikvision_person_id' => $person->id,
    ]);

    // Create event first time
    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'dev1:123',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-25 08:30:00',
        'person_name' => 'Idempotent Employee',
        'person_hikvision_id' => 'p-idemp-1',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    expect(HikvisionAccessEvent::query()->where('company_id', $company->id)->count())->toBe(1);

    // Sync attendance
    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-25', 'Asia/Dubai');
    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $record = AttendanceRecord::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-25')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in?->format('H:i'))->toBe('08:30');

    // Run sync again without new events - count and record remain unchanged
    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    expect(AttendanceRecord::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->whereDate('date', '2026-06-25')->count())->toBe(1);
});

test('existing attendance is updated when newly available mobile events appear later', function () {
    $company = hikvisionTestCompany();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'p-mobile-update',
        'full_name' => 'Mobile Update Employee',
    ]);

    $employee = Employee::factory()->for($company)->create([
        'status' => 'active',
        'name' => 'Mobile Update Employee',
        'hikvision_person_id' => $person->id,
    ]);

    // Morning checkin from device
    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'dev1:101',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-25 08:30:00',
        'person_name' => 'Mobile Update Employee',
        'person_hikvision_id' => 'p-mobile-update',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-25', 'Asia/Dubai');
    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $record = AttendanceRecord::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-25')
        ->first();

    expect($record->clock_in?->format('H:i'))->toBe('08:30')
        ->and($record->clock_out)->toBeNull();

    // Later, mobile app checkout event arrives
    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'mobile:checkout:999',
        'msg_type' => 'attendance/mobile',
        'occurrence_time' => '2026-06-25 17:35:00',
        'person_name' => 'Mobile Update Employee',
        'person_hikvision_id' => 'p-mobile-update',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'fetched_at' => now(),
    ]);

    // Resync
    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $updatedRecord = $record->fresh();
    expect($updatedRecord->clock_in?->format('H:i'))->toBe('08:30')
        ->and($updatedRecord->clock_out?->format('H:i'))->toBe('17:35');
});

test('manual attendance records remain protected from Hikvision overwrite', function () {
    $company = hikvisionTestCompany();

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'p-manual-protect',
        'full_name' => 'Manual Protected Employee',
    ]);

    $employee = Employee::factory()->for($company)->create([
        'status' => 'active',
        'name' => 'Manual Protected Employee',
        'hikvision_person_id' => $person->id,
    ]);

    // Create a manually locked attendance record
    $record = AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-25',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'clock_in' => '2026-06-25 09:00:00',
        'clock_out' => '2026-06-25 18:00:00',
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    // Hikvision event has earlier time
    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'dev1:manual-overwrite-test',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-25 08:00:00',
        'person_name' => 'Manual Protected Employee',
        'person_hikvision_id' => 'p-manual-protect',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-25', 'Asia/Dubai');
    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $freshRecord = $record->fresh();
    expect($freshRecord->clock_in?->format('H:i'))->toBe('09:00')
        ->and($freshRecord->source)->toBe(AttendanceRecord::SOURCE_MANUAL);
});

test('tenant isolation: access events of another company cannot affect attendance sync', function () {
    $companyA = hikvisionTestCompany();
    $companyB = additionalHikvisionTestCompany($companyA, 'iso-b');

    $personA = HikvisionPerson::query()->create([
        'company_id' => $companyA->id,
        'person_id' => 'person-iso-100',
        'full_name' => 'Same Person Name',
        'person_code' => '500',
    ]);

    $personB = HikvisionPerson::query()->create([
        'company_id' => $companyB->id,
        'person_id' => 'person-iso-100',
        'full_name' => 'Same Person Name',
        'person_code' => '500',
    ]);

    $employeeA = Employee::factory()->for($companyA)->create([
        'status' => 'active',
        'name' => 'Same Person Name',
        'hikvision_person_id' => $personA->id,
    ]);

    $employeeB = Employee::factory()->for($companyB)->create([
        'status' => 'active',
        'name' => 'Same Person Name',
        'hikvision_person_id' => $personB->id,
    ]);

    // Create access events only in Company B
    HikvisionAccessEvent::query()->create([
        'company_id' => $companyB->id,
        'system_id' => 'iso:b:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-25 08:30:00',
        'person_name' => 'Same Person Name',
        'person_hikvision_id' => 'person-iso-100',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'company_id' => $companyB->id,
        'system_id' => 'iso:b:checkout',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-25 17:30:00',
        'person_name' => 'Same Person Name',
        'person_hikvision_id' => 'person-iso-100',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    // Sync Company A
    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-25', 'Asia/Dubai');
    $sync->syncCompany($companyA->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $recordA = AttendanceRecord::query()
        ->where('company_id', $companyA->id)
        ->where('employee_id', $employeeA->id)
        ->whereDate('date', '2026-06-25')
        ->first();

    // Employee A has no events in Company A, so status should be absent (no punches)
    expect($recordA)->not->toBeNull()
        ->and($recordA->status)->toBe(AttendanceRecord::STATUS_ABSENT)
        ->and($recordA->clock_in)->toBeNull();

    // Sync Company B
    $sync->syncCompany($companyB->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    $recordB = AttendanceRecord::query()
        ->where('company_id', $companyB->id)
        ->where('employee_id', $employeeB->id)
        ->whereDate('date', '2026-06-25')
        ->first();

    expect($recordB)->not->toBeNull()
        ->and($recordB->status)->toBe(AttendanceRecord::STATUS_PRESENT)
        ->and($recordB->clock_in?->format('H:i'))->toBe('08:30')
        ->and($recordB->clock_out?->format('H:i'))->toBe('17:30');
});

test('if FetchHikvisionAccessEventsJob fails reconciliation is marked failed and scheduler retries on next run', function () {
    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);
    $settings = configuredHikvisionSettings($company->id);

    $job = new FetchHikvisionAccessEventsJob($settings->id, '2026-06-25', HikvisionFetchOrigin::ScheduledReconciliation);
    $job->failed(new RuntimeException('Hikvision API connection timeout'));

    expect(HikvisionReconciliation::isReconciled($company->id, '2026-06-25'))->toBeFalse();
    $reconciliation = HikvisionReconciliation::query()
        ->forCompany($company->id)
        ->whereDate('target_date', '2026-06-25')
        ->first();

    expect($reconciliation)->not->toBeNull()
        ->and($reconciliation->status)->toBe(HikvisionReconciliation::STATUS_FAILED);
});

test('if SyncCompanyHikvisionAttendanceJob fails reconciliation is marked failed and retried on next run', function () {
    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);

    // Initially mark completed by fetch job
    HikvisionReconciliation::markCompleted(
        $company->id,
        '2026-06-25',
        HikvisionFetchOrigin::ScheduledReconciliation,
        5,
        5,
        0,
    );

    expect(HikvisionReconciliation::isReconciled($company->id, '2026-06-25'))->toBeTrue();

    // Now simulate attendance sync failure
    $attendanceJob = new SyncCompanyHikvisionAttendanceJob($company->id, '2026-06-25 00:00:00', '2026-06-25 23:59:59');
    $attendanceJob->failed(new RuntimeException('Database deadlock during attendance sync'));

    expect(HikvisionReconciliation::isReconciled($company->id, '2026-06-25'))->toBeFalse();
    $reconciliation = HikvisionReconciliation::query()
        ->forCompany($company->id)
        ->whereDate('target_date', '2026-06-25')
        ->first();

    expect($reconciliation->status)->toBe(HikvisionReconciliation::STATUS_FAILED);
});

test('multi-day outage recovery catches up missed days in chronological order', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:30:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 3]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);
    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // Scheduler was down for 3 days: 2026-06-23, 2026-06-24, 2026-06-25 are all unreconciled
    $due = HikvisionAccessEventsFetchSchedule::dueReconciliations();

    expect($due)->toHaveCount(3)
        ->and($due[0]['target_date'])->toBe('2026-06-23')
        ->and($due[0]['origin'])->toBe(HikvisionFetchOrigin::CatchUp)
        ->and($due[1]['target_date'])->toBe('2026-06-24')
        ->and($due[1]['origin'])->toBe(HikvisionFetchOrigin::CatchUp)
        ->and($due[2]['target_date'])->toBe('2026-06-25')
        ->and($due[2]['origin'])->toBe(HikvisionFetchOrigin::ScheduledReconciliation);

    // First scheduler run dispatches the oldest missed date (2026-06-23)
    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('Dispatched Hikvision access events fetch job.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, function (FetchHikvisionAccessEventsJob $job) use ($settings): bool {
        return $job->hikvisionSettingId === $settings->id
            && $job->date === '2026-06-23'
            && $job->origin === HikvisionFetchOrigin::CatchUp;
    });
});

test('previously completed date inside lookback window is eligible for stabilization replay on next daily cycle', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-27 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 3]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);
    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // 2026-06-25 was successfully processed yesterday (2026-06-26 18:05:00)
    $reconciliation = HikvisionReconciliation::markCompleted(
        $company->id,
        '2026-06-25',
        HikvisionFetchOrigin::ScheduledReconciliation,
        10,
        10,
        0,
    );
    $reconciliation->update(['reconciled_at' => '2026-06-26 18:05:00']);

    // Today (2026-06-27 18:00:00), 2026-06-26 is yesterday (unprocessed), 2026-06-25 is in stabilization window
    $due = HikvisionAccessEventsFetchSchedule::dueReconciliations();

    expect(HikvisionReconciliation::wasSuccessfullyProcessed($company->id, '2026-06-25'))->toBeTrue();

    $dates = $due->pluck('target_date')->all();
    expect($dates)->toContain('2026-06-26')
        ->and($dates)->toContain('2026-06-25')
        ->and($due[0]['target_date'])->toBe('2026-06-24') // Unprocessed 3 days ago
        ->and($due[1]['target_date'])->toBe('2026-06-26') // Unprocessed yesterday
        ->and($due[2]['target_date'])->toBe('2026-06-25'); // Completed stabilization replay
});

test('completed date is not repeatedly dispatched within the same reconciliation cycle', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-27 18:05:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 2]);

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);
    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // Both dates were already processed during today's 18:00 cycle (at 18:01 and 18:02)
    $rec1 = HikvisionReconciliation::markCompleted($company->id, '2026-06-25', HikvisionFetchOrigin::CatchUp, 5, 5, 0);
    $rec1->update(['reconciled_at' => '2026-06-27 18:01:00']);

    $rec2 = HikvisionReconciliation::markCompleted($company->id, '2026-06-26', HikvisionFetchOrigin::ScheduledReconciliation, 8, 8, 0);
    $rec2->update(['reconciled_at' => '2026-06-27 18:02:00']);

    // On minute 18:05 of the same cycle, nothing should be due
    expect(HikvisionAccessEventsFetchSchedule::dueReconciliations())->toBeEmpty();

    $this->artisan('hikvision:fetch-access-events')
        ->assertSuccessful()
        ->expectsOutputToContain('No Hikvision companies are due for scheduled fetch.');

    Queue::assertNothingPushed();
});

test('delayed mobile records appear on subsequent stabilization replay cycle and update attendance', function () {
    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);
    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'p-delayed-mobile',
        'full_name' => 'Delayed Mobile Employee',
    ]);

    $employee = Employee::factory()->for($company)->create([
        'status' => 'active',
        'name' => 'Delayed Mobile Employee',
        'hikvision_person_id' => $person->id,
    ]);

    // DAY 1 (2026-06-24): Physical door checkin
    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'door:checkin:20260624',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-24 08:30:00',
        'person_name' => 'Delayed Mobile Employee',
        'person_hikvision_id' => 'p-delayed-mobile',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => '2026-06-25 18:00:00',
    ]);

    // DAY 2 (2026-06-25 18:00): First reconciliation runs for 2026-06-24
    Carbon::setTestNow(Carbon::parse('2026-06-25 18:00:00', 'Asia/Dubai'));
    $sync = app(SyncAttendanceRecordsFromHikvision::class);
    $day = Carbon::parse('2026-06-24', 'Asia/Dubai');
    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());

    HikvisionReconciliation::markCompleted($company->id, '2026-06-24', HikvisionFetchOrigin::ScheduledReconciliation, 1, 1, 0);

    $record = AttendanceRecord::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-24')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($record->clock_in?->format('H:i'))->toBe('08:30')
        ->and($record->clock_out)->toBeNull();

    // DAY 3 (2026-06-26 18:00): Later, mobile app checkout event becomes available in Hikvision
    Carbon::setTestNow(Carbon::parse('2026-06-26 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 3]);

    // 2026-06-24 is 2 days ago, inside stabilization window -> eligible for replay
    $due = HikvisionAccessEventsFetchSchedule::dueReconciliations();
    expect($due->pluck('target_date')->all())->toContain('2026-06-24');

    // Simulate stabilization replay importing late mobile checkout
    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'mobile:checkout:20260624',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-24 17:45:00',
        'person_name' => 'Delayed Mobile Employee',
        'person_hikvision_id' => 'p-delayed-mobile',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'fetched_at' => '2026-06-26 18:00:00',
    ]);

    $sync->syncCompany($company->id, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    HikvisionReconciliation::markCompleted($company->id, '2026-06-24', HikvisionFetchOrigin::CatchUp, 2, 1, 1);

    // Verify attendance is updated in-place without duplicate records
    expect(AttendanceRecord::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->whereDate('date', '2026-06-24')->count())->toBe(1);
    expect(HikvisionAccessEvent::query()->where('company_id', $company->id)->count())->toBe(2);

    $updatedRecord = $record->fresh();
    expect($updatedRecord->source)->toBe(AttendanceRecord::SOURCE_MOBILE)
        ->and($updatedRecord->clock_in?->format('H:i'))->toBe('08:30')
        ->and($updatedRecord->clock_out?->format('H:i'))->toBe('17:45');
});

test('dates outside lookback window exit automatic stabilization and are not re-fetched', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-28 18:00:00', 'Asia/Dubai'));
    config(['hikvision.reconciliation_lookback_days' => 2]); // Lookback is only 2 days (2026-06-27 and 2026-06-26)

    $company = hikvisionTestCompany();
    $company->update(['timezone' => 'Asia/Dubai']);
    $settings = configuredHikvisionSettings($company->id);
    $settings->update([
        'events_fetch_schedule_enabled' => true,
        'events_fetch_schedule_at' => '18:00',
    ]);

    // 2026-06-25 was processed 3 days ago (outside 2-day lookback)
    HikvisionReconciliation::markCompleted($company->id, '2026-06-25', HikvisionFetchOrigin::CatchUp, 5, 5, 0);

    // Both inside-lookback dates were processed today
    $rec1 = HikvisionReconciliation::markCompleted($company->id, '2026-06-26', HikvisionFetchOrigin::CatchUp, 5, 5, 0);
    $rec1->update(['reconciled_at' => '2026-06-28 18:01:00']);

    $rec2 = HikvisionReconciliation::markCompleted($company->id, '2026-06-27', HikvisionFetchOrigin::ScheduledReconciliation, 5, 5, 0);
    $rec2->update(['reconciled_at' => '2026-06-28 18:02:00']);

    // 2026-06-25 is outside lookback window, so it is never re-fetched
    expect(HikvisionAccessEventsFetchSchedule::dueReconciliations())->toBeEmpty();
});
