<?php

use App\Enums\Recruitment\CandidateReminderScheduleType;
use App\Enums\Recruitment\CandidateReminderStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Jobs\DeliverCandidateInternalReminderJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateInternalReminder;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\RecruitmentRequirementNotificationRecipient;
use App\Models\User;
use App\Support\Notifications\BuildUnifiedNotificationFeed;
use App\Support\Recruitment\Candidates\DispatchCandidateInternalReminders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    $country = Country::query()->firstOrCreate(
        ['code' => 'AE'],
        ['name' => 'United Arab Emirates', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'Dirham', 'symbol' => 'د.إ', 'is_active' => true],
    );

    $this->company = Company::query()->create([
        'name' => 'Reminder Corp',
        'slug' => 'reminder-corp-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai', // UTC+4
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create(['name' => 'Reminder Client', 'is_active' => true]);
    $this->position = Position::query()->create(['company_id' => $this->company->id, 'title' => 'Engineer', 'status' => 'active']);

    $this->recruiter = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Recruiter User', 'status' => 'active']);
    $this->submitter = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Submitter User', 'status' => 'active']);
    $this->ccUser = User::factory()->create(['company_id' => $this->company->id, 'name' => 'CC User', 'status' => 'active']);
    $this->inactiveUser = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Inactive User', 'status' => 'inactive']);

    foreach ([$this->recruiter, $this->submitter, $this->ccUser, $this->inactiveUser] as $u) {
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $u->id],
            ['status' => $u->status, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    foreach (['recruitment.candidates.view', 'recruitment.requirements.view'] as $perm) {
        $p = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $this->recruiter->givePermissionTo($p);
        $this->submitter->givePermissionTo($p);
        $this->ccUser->givePermissionTo($p);
    }

    $this->requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'client_id' => $this->client->id,
        'requirement_number' => 'REQ-REM-1',
        'status' => RequirementStatus::Open,
        'priority' => 'normal',
        'request_received_date' => '2026-10-01',
        'required_by_date' => '2026-10-30',
        'total_headcount' => 5,
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->submitter->id,
        'submitted_by' => $this->submitter->id,
    ]);

    // Add CC recipient and also add duplicate recruiter to verify deduplication
    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'user_id' => $this->ccUser->id,
    ]);
    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'user_id' => $this->recruiter->id, // Duplicate of assigned recruiter
    ]);
    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'user_id' => $this->inactiveUser->id, // Inactive user
    ]);

    $this->line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 5,
        'status' => RequirementLineStatus::Open,
    ]);
});

test('interview reminders are dispatched 1 day before and day of in company timezone with deduplicated recipients', function (): void {
    Queue::fake();

    // In Asia/Dubai (UTC+4), assume current local time is 2026-10-15 09:30:00 (which is 2026-10-15 05:30:00 UTC)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    // Candidate 1: Interview today (2026-10-15 14:00 Dubai = 2026-10-15 10:00 UTC)
    $candToday = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Alice Interview Today',
        'stage' => CandidateStage::Interview,
        'interview_scheduled_at' => '2026-10-15 10:00:00',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Candidate 2: Interview tomorrow (2026-10-16 11:00 Dubai = 2026-10-16 07:00 UTC)
    $candTomorrow = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Bob Interview Tomorrow',
        'stage' => CandidateStage::Interview,
        'interview_scheduled_at' => '2026-10-16 07:00:00',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $dispatcher = app(DispatchCandidateInternalReminders::class);
    $result = $dispatcher->forCompany($this->company->id, force: true);

    // Recipients should be recruiter, submitter, ccUser (3 distinct active users; inactive user excluded)
    // For candToday (day_of): 3 reminders
    // For candTomorrow (1_day_before): 3 reminders
    // Total queued = 6
    expect($result['queued'])->toBe(6)
        ->and(RecruitmentCandidateInternalReminder::query()->count())->toBe(6);

    Queue::assertPushed(DeliverCandidateInternalReminderJob::class, 6);

    // Check recipients were deduplicated
    $recipientsToday = RecruitmentCandidateInternalReminder::query()
        ->where('recruitment_candidate_id', $candToday->id)
        ->pluck('user_id')
        ->all();

    expect(sort($recipientsToday))->toBe(true);
    expect($recipientsToday)->toHaveCount(3);
    expect($recipientsToday)->toContain($this->recruiter->id, $this->submitter->id, $this->ccUser->id);
    expect($recipientsToday)->not->toContain($this->inactiveUser->id);
});

test('joining reminders are dispatched 7 days before, 3 days before, and day of', function (): void {
    Queue::fake();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC')); // 09:30 Dubai

    // Candidate 1: Joining today (2026-10-15)
    $candDayOf = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Joining Day Of',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Candidate 2: Joining in 3 days (2026-10-18)
    $cand3Days = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Joining 3 Days',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-18',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Candidate 3: Joining in 7 days (2026-10-22)
    $cand7Days = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Joining 7 Days',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-22',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $dispatcher = app(DispatchCandidateInternalReminders::class);
    $result = $dispatcher->forCompany($this->company->id, force: true);

    // 3 candidates * 3 active users = 9 reminders queued
    expect($result['queued'])->toBe(9);
    Queue::assertPushed(DeliverCandidateInternalReminderJob::class, 9);
});

test('overdue joining reminders are dispatched daily up to 7 days overdue', function (): void {
    Queue::fake();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC')); // 09:30 Dubai

    // Candidate overdue by 2 days (expected 2026-10-13)
    $candOverdue2 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Overdue 2 Days',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-13',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Candidate overdue by 8 days (> 7 days, should NOT receive overdue reminder)
    $candOverdue8 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Overdue 8 Days',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-07',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $dispatcher = app(DispatchCandidateInternalReminders::class);
    $result = $dispatcher->forCompany($this->company->id, force: true);

    // Only candOverdue2 gets queued (3 active users)
    expect($result['queued'])->toBe(3);

    $reminders = RecruitmentCandidateInternalReminder::query()
        ->where('recruitment_candidate_id', $candOverdue2->id)
        ->get();

    expect($reminders)->toHaveCount(3);
    foreach ($reminders as $rem) {
        expect($rem->schedule_type)->toBe(CandidateReminderScheduleType::OverdueJoining->value)
            ->and($rem->milestone)->toBe('overdue_2');
    }

    // candOverdue8 gets no reminders
    expect(RecruitmentCandidateInternalReminder::query()
        ->where('recruitment_candidate_id', $candOverdue8->id)
        ->count())->toBe(0);
});

test('DeliverCandidateInternalReminderJob delivers successfully and idempotently', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Diana Prince',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $reminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    $job = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $reminder->id,
        'company_id' => $this->company->id,
    ]);

    $job->handle();

    $reminder->refresh();
    expect($reminder->status)->toBe(CandidateReminderStatus::Sent->value)
        ->and($reminder->sent_at)->not->toBeNull()
        ->and($reminder->title)->toContain('Joining Today: Diana Prince')
        ->and($reminder->summary)->toContain('Diana Prince')
        ->and($reminder->url)->toBe(route('organization.recruitment.candidates.show', $candidate->id));

    $sentAt = $reminder->sent_at;

    // Running again does not change or duplicate
    $job->handle();
    $reminder->refresh();
    expect($reminder->status)->toBe(CandidateReminderStatus::Sent->value)
        ->and($reminder->sent_at->toDateTimeString())->toBe($sentAt->toDateTimeString());
});

test('DeliverCandidateInternalReminderJob skips delivery if interview or joining was rescheduled', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Clark Kent',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-20', // Rescheduled to 20th!
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Old reminder for 2026-10-15
    $reminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    $job = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $reminder->id,
        'company_id' => $this->company->id,
    ]);

    $job->handle();

    $reminder->refresh();
    expect($reminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($reminder->skip_reason)->toBe('joining_rescheduled');
});

test('stopping reminders when candidate outcome is decided or stage moves to joined', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    // Candidate moved to Joined
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Bruce Wayne',
        'stage' => CandidateStage::Joined, // Already joined!
        'actual_joining_date' => '2026-10-15',
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $reminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    $job = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $reminder->id,
        'company_id' => $this->company->id,
    ]);

    $job->handle();

    $reminder->refresh();
    expect($reminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($reminder->skip_reason)->toBe('candidate_stage_changed');
});

test('reclaims stale queued reminder records', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Barry Allen',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Created 30 minutes ago and stuck in Queued status
    $staleReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now()->subMinutes(30),
    ]);
    DB::table('recruitment_candidate_internal_reminders')->where('id', $staleReminder->id)->update([
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);

    Queue::fake();

    $dispatcher = app(DispatchCandidateInternalReminders::class);
    $result = $dispatcher->forCompany($this->company->id, force: true);

    $staleReminder->refresh();
    expect($staleReminder->status)->toBe(CandidateReminderStatus::Queued->value)
        ->and($staleReminder->claimed_at->toDateTimeString())->toBe(now()->toDateTimeString());

    Queue::assertPushed(DeliverCandidateInternalReminderJob::class);
});

test('delivered internal reminder appears in unified feed and can be marked as read', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Arthur Curry',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $reminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Sent->value,
        'title' => 'Joining Today: Arthur Curry',
        'summary' => 'Candidate Arthur Curry expected to join today',
        'url' => route('organization.recruitment.candidates.show', $candidate->id),
        'sent_at' => now(),
    ]);

    $feedBuilder = app(BuildUnifiedNotificationFeed::class);
    $feed = $feedBuilder->forUser($this->recruiter, $this->company->id);

    expect($feed['unread_count'])->toBeGreaterThanOrEqual(1);
    $found = collect($feed['items'])->firstWhere('id', "candidate_internal_reminder:{$reminder->id}");
    expect($found)->not->toBeNull()
        ->and($found['title'])->toBe('Joining Today: Arthur Curry')
        ->and($found['is_read'])->toBeFalse();

    // Mark as read via HTTP
    $response = $this->actingAs($this->recruiter)
        ->postJson(route('organization.notifications.candidate-reminders.read', $reminder->id));

    $response->assertOk();

    $reminder->refresh();
    expect($reminder->read_at)->not->toBeNull();
});

test('dispatch artisan command runs successfully with force flag', function (): void {
    $this->artisan('recruitment:dispatch-candidate-reminders', ['--force' => true, '--company' => $this->company->id])
        ->assertSuccessful();
});

test('revalidates recipient qualification at delivery and skips reassigned recruiter, removed CC, and revoked memberships', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Recipient Test Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // 1. Reassigned recruiter: Reminder was queued for recruiter, but requirement was reassigned to submitter
    $recruiterReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    // Reassign requirement to submitter and remove recruiter from CC
    $this->requirement->update(['assigned_to' => $this->submitter->id]);
    RecruitmentRequirementNotificationRecipient::query()
        ->where('recruitment_requirement_id', $this->requirement->id)
        ->where('user_id', $this->recruiter->id)
        ->delete();

    $job1 = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $recruiterReminder->id,
        'company_id' => $this->company->id,
    ]);
    $job1->handle();

    $recruiterReminder->refresh();
    expect($recruiterReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($recruiterReminder->skip_reason)->toBe('unqualified_recipient');

    // 2. Removed CC: Reminder queued for CC user, but CC user was removed before delivery
    $ccReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->ccUser->id,
        'delivery_key' => "user_{$this->ccUser->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    RecruitmentRequirementNotificationRecipient::query()
        ->where('recruitment_requirement_id', $this->requirement->id)
        ->where('user_id', $this->ccUser->id)
        ->delete();

    $job2 = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $ccReminder->id,
        'company_id' => $this->company->id,
    ]);
    $job2->handle();

    $ccReminder->refresh();
    expect($ccReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($ccReminder->skip_reason)->toBe('unqualified_recipient');

    // 3. Revoked membership: Reminder queued for newly assigned recruiter, but membership was revoked
    $revokedUser = User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $this->company->id,
        'user_id' => $revokedUser->id,
        'status' => 'inactive', // revoked membership
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->requirement->update(['assigned_to' => $revokedUser->id]);

    $revokedReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $revokedUser->id,
        'delivery_key' => "user_{$revokedUser->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    $job3 = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $revokedReminder->id,
        'company_id' => $this->company->id,
    ]);
    $job3->handle();

    $revokedReminder->refresh();
    expect($revokedReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($revokedReminder->skip_reason)->toBe('revoked_membership');

    // 4. Reassigned recruiter who qualifies through active CC delivers successfully
    // Former recruiter added to CC notification recipients
    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'user_id' => $this->recruiter->id,
    ]);

    $candidate2 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Recipient Test Candidate 2',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $qualifyingReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate2->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    $job4 = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $qualifyingReminder->id,
        'company_id' => $this->company->id,
    ]);
    $job4->handle();

    $qualifyingReminder->refresh();
    expect($qualifyingReminder->status)->toBe(CandidateReminderStatus::Sent->value)
        ->and($qualifyingReminder->sent_at)->not->toBeNull();
});

test('expires outdated queued reminders when delivered after milestone date across midnight or multiple days', function (): void {
    // Current time is 2026-10-15 05:30:00 UTC (09:30 Dubai)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Delayed Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-14', // Milestone date was yesterday!
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // Queued reminder whose intended delivery was day_of 2026-10-14 (yesterday)
    $expiredReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-14',
        'milestone' => 'day_of',
        'target_date' => '2026-10-14',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now()->subDay(),
    ]);

    // Delivery job executes today (delayed across midnight/multiple days)
    $job = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $expiredReminder->id,
        'company_id' => $this->company->id,
    ]);
    $job->handle();

    $expiredReminder->refresh();
    expect($expiredReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($expiredReminder->skip_reason)->toBe('milestone_expired');

    // Test rescheduling recheck: candidate date rescheduled from 2026-10-15 to 2026-10-20
    $candidate->update(['expected_joining_date' => '2026-10-20']);
    $rescheduledReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15', // Old schedule key
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now(),
    ]);

    $job2 = new DeliverCandidateInternalReminderJob([
        'reminder_id' => $rescheduledReminder->id,
        'company_id' => $this->company->id,
    ]);
    $job2->handle();

    $rescheduledReminder->refresh();
    expect($rescheduledReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($rescheduledReminder->skip_reason)->toBe('joining_rescheduled');
});

test('independent recovery sweep re-queues eligible records and skips expired or obsolete records atomically', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 05:30:00', 'UTC'));

    $candidateToday = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Sweep Today Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    $candidateYesterday = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Sweep Yesterday Candidate',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-14',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);

    // 1. Record stranded overnight from yesterday: stuck in Queued
    $strandedReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidateYesterday->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-14',
        'milestone' => 'day_of',
        'target_date' => '2026-10-14',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Queued->value,
        'claimed_at' => now()->subHours(12),
    ]);

    // 2. Record for today stuck in Pending (queue dispatch failure or worker crash)
    $failedDispatchReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidateToday->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Pending->value,
    ]);

    // 3. Obsolete record: candidate already moved to Joined
    $obsoleteCandidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Obsolete Joined Candidate',
        'stage' => CandidateStage::Joined,
        'expected_joining_date' => '2026-10-15',
        'lock_version' => 1,
        'position_title_snapshot' => 'Engineer',
        'requirement_number_snapshot' => 'REQ-REM-1',
    ]);
    $obsoleteReminder = RecruitmentCandidateInternalReminder::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $obsoleteCandidate->id,
        'schedule_type' => CandidateReminderScheduleType::Joining->value,
        'schedule_key' => '2026-10-15',
        'milestone' => 'day_of',
        'target_date' => '2026-10-15',
        'user_id' => $this->recruiter->id,
        'delivery_key' => "user_{$this->recruiter->id}",
        'status' => CandidateReminderStatus::Pending->value,
    ]);

    Queue::fake();

    $dispatcher = app(DispatchCandidateInternalReminders::class);
    $recovery = $dispatcher->recoverStaleRemindersForCompany($this->company->id);

    // Stranded record skipped with milestone_expired
    $strandedReminder->refresh();
    expect($strandedReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($strandedReminder->skip_reason)->toBe('milestone_expired');

    // Obsolete record skipped with candidate_stage_changed
    $obsoleteReminder->refresh();
    expect($obsoleteReminder->status)->toBe(CandidateReminderStatus::Skipped->value)
        ->and($obsoleteReminder->skip_reason)->toBe('candidate_stage_changed');

    // Failed dispatch record recovered and claimed atomically
    $failedDispatchReminder->refresh();
    expect($failedDispatchReminder->status)->toBe(CandidateReminderStatus::Queued->value)
        ->and($failedDispatchReminder->claimed_at)->not->toBeNull()
        ->and($recovery['recovered'])->toBe(1)
        ->and($recovery['skipped'])->toBe(2);

    Queue::assertPushed(DeliverCandidateInternalReminderJob::class, function ($job) use ($failedDispatchReminder) {
        return $job->payload['reminder_id'] === $failedDispatchReminder->id;
    });

    // Idempotency: running recovery immediately again skips the recently claimed record
    Queue::fake();
    $recovery2 = $dispatcher->recoverStaleRemindersForCompany($this->company->id);
    expect($recovery2['recovered'])->toBe(0);
    Queue::assertNothingPushed();
});
