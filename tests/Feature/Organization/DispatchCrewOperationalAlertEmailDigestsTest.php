<?php

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use App\Models\CrewOperationalAlert;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationsSetting;
use App\Models\User;
use App\Support\CrewOperations\ClaimCrewOperationalAlertEmailDeliveries;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\DispatchCrewOperationalAlertEmailDigests;
use Carbon\CarbonImmutable;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Queue;

test('scheduled digest dispatches queued deliveries when local digest time is reached', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:1',
        'title' => 'Sign-off approaching — no relief',
        'message' => 'Approaching in 14 days',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    // 07:59 in Asia/Dubai -> should NOT dispatch
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 07:59:00', 'Asia/Dubai'));
    $resultBefore = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultBefore['dispatched'])->toBeFalse()
        ->and($resultBefore['reason'])->toBe('not_due_yet');
    Queue::assertNothingPushed();

    // 08:01 in Asia/Dubai -> SHOULD dispatch
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:01:00', 'Asia/Dubai'));
    $resultAtTime = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultAtTime['dispatched'])->toBeTrue()
        ->and($resultAtTime['jobs_count'])->toBe(1)
        ->and($resultAtTime['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, function (DeliverCrewOperationalAlertEmailJob $job) use ($delivery, $companyId, $user): bool {
        return $job->deliveryIds === [(int) $delivery->id]
            && $job->companyId === $companyId
            && $job->userId === (int) $user->id;
    });

    $setting = CrewOperationsSetting::query()->where('company_id', $companyId)->first();
    expect($setting?->notification_email_last_digest_date)->toBe('2026-08-21')
        ->and($setting?->notification_email_last_digest_dispatched_at)->not->toBeNull();

    // Running again same day at 08:15 -> should NOT dispatch (already dispatched today)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:15:00', 'Asia/Dubai'));
    $resultAgain = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resultAgain['dispatched'])->toBeFalse()
        ->and($resultAgain['reason'])->toBe('already_dispatched_today');

    CarbonImmutable::setTestNow();
});

test('artisan command crew:dispatch-operational-alert-email-digests runs successfully', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'UTC']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:artisan:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:05:00', 'UTC'));

    $this->artisan('crew:dispatch-operational-alert-email-digests', ['--company' => $companyId])
        ->expectsOutputToContain('dispatched 1 digest(s) across 1 job(s)')
        ->assertSuccessful();

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

test('force flag bypasses time-of-day and already-sent checks', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:force:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    // Test at 02:00 (way before 08:00) with force => SHOULD dispatch
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 02:00:00', 'Asia/Dubai'));

    $this->artisan('crew:dispatch-operational-alert-email-digests', [
        '--company' => $companyId,
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

test('scheduled digest groups multiple queued alerts into ONE job per recipient', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $u1 = $fixtures['user'];
    $u2 = User::factory()->create(['email' => 'u2-digest@example.test']);
    $u2->companies()->attach($companyId, ['status' => 'active']);

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $u1->id, (int) $u2->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alerts = createFiveAlertsForDigestDispatch($companyId);

    // Queue all 5 alerts for both u1 and u2 (10 delivery rows)
    foreach ($alerts as $alert) {
        CrewOperationalAlertEmailDelivery::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $u1->id,
            'notification_version' => 1,
            'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
            'queued_at' => now(),
            'attempt_count' => 0,
        ]);
        CrewOperationalAlertEmailDelivery::query()->create([
            'company_id' => $companyId,
            'crew_operational_alert_id' => $alert->id,
            'user_id' => $u2->id,
            'notification_version' => 1,
            'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
            'queued_at' => now(),
            'attempt_count' => 0,
        ]);
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    expect($result['dispatched'])->toBeTrue()
        ->and($result['jobs_count'])->toBe(2)
        ->and($result['delivery_count'])->toBe(10);

    // Exactly 2 jobs pushed: 1 for u1 (with 5 delivery IDs), 1 for u2 (with 5 delivery IDs)
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);

    CarbonImmutable::setTestNow();
});

test('companies in different timezones dispatch at their own local scheduled times', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixturesDubai = makeCrewAssignmentFixtures();
    $companyDubai = $fixturesDubai['company'];
    $companyDubai->update(['timezone' => 'Asia/Dubai']);
    $userDubai = $fixturesDubai['user'];

    $fixturesNy = makeCrewAssignmentFixtures();
    $companyNy = $fixturesNy['company'];
    $companyNy->update(['timezone' => 'America/New_York']);
    $userNy = $fixturesNy['user'];

    CrewOperationsSettings::saveSettings((int) $companyDubai->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $userDubai->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    CrewOperationsSettings::saveSettings((int) $companyNy->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $userNy->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alertDubai = CrewOperationalAlert::query()->create([
        'company_id' => $companyDubai->id,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:dubai:1',
        'title' => 'Sign-off approaching Dubai',
        'message' => 'Test',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyDubai->id,
        'crew_operational_alert_id' => $alertDubai->id,
        'user_id' => $userDubai->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    $alertNy = CrewOperationalAlert::query()->create([
        'company_id' => $companyNy->id,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:ny:1',
        'title' => 'Sign-off approaching NY',
        'message' => 'Test',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyNy->id,
        'crew_operational_alert_id' => $alertNy->id,
        'user_id' => $userNy->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    // At 04:00 UTC:
    // Asia/Dubai (UTC+4) is 08:00 => DUE!
    // America/New_York (UTC-4) is 00:00 => NOT DUE!
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 04:00:00', 'UTC'));

    $dispatcher = app(DispatchCrewOperationalAlertEmailDigests::class);
    $resDubai = $dispatcher->forCompany((int) $companyDubai->id);
    $resNy = $dispatcher->forCompany((int) $companyNy->id);

    expect($resDubai['dispatched'])->toBeTrue()
        ->and($resNy['dispatched'])->toBeFalse()
        ->and($resNy['reason'])->toBe('not_due_yet');

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

function createFiveAlertsForDigestDispatch(int $companyId): array
{
    $now = now();

    $a1 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:assignment:101',
        'title' => 'Sign-off overdue',
        'message' => 'John Doe overdue.',
        'context' => ['employee_name' => 'John Doe'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a2 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:assignment:102',
        'title' => 'Sign-off approaching — no relief',
        'message' => 'Jane Smith approaching sign-off.',
        'context' => ['employee_name' => 'Jane Smith'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a3 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::ReliefNotReady,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'relief_not_ready:assignment:103',
        'title' => 'Relief not ready',
        'message' => 'Relief has not confirmed.',
        'context' => ['employee_name' => 'Bob Builder'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a4 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::CurrentManningGap,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'current_manning_gap:vessel:1:rank:2',
        'title' => 'Current manning gap',
        'message' => 'Pacific Trader has a gap.',
        'context' => ['vessel_name' => 'Pacific Trader'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    $a5 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::ProjectedManningGap,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'projected_manning_gap:vessel:2:rank:3',
        'title' => 'Projected manning gap',
        'message' => 'Ocean Star has a projected gap.',
        'context' => ['vessel_name' => 'Ocean Star'],
        'detected_at' => $now,
        'last_detected_at' => $now,
        'notification_version' => 1,
    ]);

    return [$a1, $a2, $a3, $a4, $a5];
}

test('warning detected after 08:00 digest is NOT sent at 09:31 and waits until next day 08:00', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    // 1. Alert 1 is detected at 07:30
    $alert1 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:after_digest_1',
        'title' => 'Sign-off approaching #1',
        'message' => 'Test 1',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $del1 = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert1->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    // 2. Dispatch runs at 08:00 Asia/Dubai -> Alert 1 is dispatched
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));
    $res8am = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res8am['dispatched'])->toBeTrue()
        ->and($res8am['delivery_count'])->toBe(1);
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    // Simulate job completing and setting status = Sent
    $del1->update([
        'status' => CrewOperationalAlertEmailDeliveryStatus::Sent,
        'sent_at' => now(),
    ]);

    // 3. New Warning Alert 2 is detected at 09:30 Asia/Dubai
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 09:30:00', 'Asia/Dubai'));
    $alert2 = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:after_digest_2',
        'title' => 'Sign-off approaching #2',
        'message' => 'Test 2',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $del2 = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert2->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    // 4. Scheduler runs at 09:31 Asia/Dubai -> must NOT dispatch (already dispatched today)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 09:31:00', 'Asia/Dubai'));
    $res931am = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($res931am['dispatched'])->toBeFalse()
        ->and($res931am['reason'])->toBe('already_dispatched_today');

    // Total jobs pushed remains 1 (from 08:00)
    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    // Alert 2 delivery row is still queued with dispatched_at null
    $del2->refresh();
    expect($del2->status)->toBe(CrewOperationalAlertEmailDeliveryStatus::Queued)
        ->and($del2->dispatched_at)->toBeNull()
        ->and($del2->sent_at)->toBeNull();

    // 5. Next morning at 08:00 Asia/Dubai (2026-08-22 08:00:00) -> Alert 2 is dispatched!
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 08:00:00', 'Asia/Dubai'));
    $resNextDay = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($resNextDay['dispatched'])->toBeTrue()
        ->and($resNextDay['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 2);

    $del2->refresh();
    expect($del2->dispatched_at)->not->toBeNull();

    CarbonImmutable::setTestNow();
});

test('scheduled dispatcher racing an immediate critical delivery does not duplicate email', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffOverdue,
        'severity' => CrewOperationalAlertSeverity::Critical,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_overdue:test:race:1',
        'title' => 'Sign-off overdue',
        'message' => 'Critical overdue',
        'context' => [],
        'detected_at' => now(),
        'last_detected_at' => now(),
        'notification_version' => 1,
    ]);

    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now(),
        'attempt_count' => 0,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    // Thread 1: Immediate path claims the delivery
    $claimedImmediate = ClaimCrewOperationalAlertEmailDeliveries::claimByIds([(int) $delivery->id]);
    expect($claimedImmediate)->toHaveCount(1);

    // Thread 2: Scheduled dispatcher runs at the exact same moment
    $resultScheduled = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);

    // Scheduled dispatcher finds 0 pending deliveries because Thread 1 claimed it
    expect($resultScheduled['dispatched'])->toBeFalse()
        ->and($resultScheduled['reason'])->toBe('no_pending_deliveries');

    // Only 0 jobs from scheduled dispatcher; immediate path dispatches exactly 1 job
    DeliverCrewOperationalAlertEmailJob::dispatch([(int) $delivery->id], $companyId, (int) $user->id);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    CarbonImmutable::setTestNow();
});

test('abandoned claimed delivery older than one hour is reclaimed and dispatched', function () {
    Queue::fake();
    EmailTemplatesSeeder::seedCrewOperationalAlertDigestTemplate();

    $fixtures = makeCrewAssignmentFixtures();
    $company = $fixtures['company'];
    $company->update(['timezone' => 'Asia/Dubai']);
    $companyId = (int) $company->id;
    $user = $fixtures['user'];

    CrewOperationsSettings::saveSettings($companyId, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [(int) $user->id],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => true,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 08:00:00', 'Asia/Dubai'));

    $alert = CrewOperationalAlert::query()->create([
        'company_id' => $companyId,
        'type' => CrewOperationalAlertType::SignoffNoRelief,
        'severity' => CrewOperationalAlertSeverity::Warning,
        'status' => CrewOperationalAlertStatus::Active,
        'dedupe_key' => 'signoff_no_relief:test:abandoned:1',
        'title' => 'Sign-off approaching',
        'message' => 'Test',
        'context' => [],
        'detected_at' => now()->subHours(3),
        'last_detected_at' => now()->subHours(3),
        'notification_version' => 1,
    ]);

    // Delivery was claimed 2 hours ago (06:00) but status is still queued (e.g. worker process killed)
    $delivery = CrewOperationalAlertEmailDelivery::query()->create([
        'company_id' => $companyId,
        'crew_operational_alert_id' => $alert->id,
        'user_id' => $user->id,
        'notification_version' => 1,
        'status' => CrewOperationalAlertEmailDeliveryStatus::Queued,
        'queued_at' => now()->subHours(3),
        'dispatched_at' => now()->subHours(2),
        'attempt_count' => 0,
    ]);

    // At 08:00 Asia/Dubai -> reclaimed and dispatched
    $result = app(DispatchCrewOperationalAlertEmailDigests::class)->forCompany($companyId);
    expect($result['dispatched'])->toBeTrue()
        ->and($result['delivery_count'])->toBe(1);

    Queue::assertPushed(DeliverCrewOperationalAlertEmailJob::class, 1);

    $delivery->refresh();
    expect($delivery->dispatched_at->toDateTimeString())->toBe(now()->toDateTimeString());

    CarbonImmutable::setTestNow();
});
